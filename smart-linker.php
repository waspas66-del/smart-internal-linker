<?php
/*
Plugin Name: Smart Internal Linker Posts Only
Description: Version 37.8. STABLE: Anti-First-Word + Logger + Bridge + Context.
Author: Krun Dev
Author URI: http://krun.pro/
License: GPL2
*/

if (!defined('ABSPATH')) exit;

function silwt_internal_links_posts_only($content) {
    if (is_admin() || !is_main_query() || !is_singular(['post', 'page']) || empty($content)) return $content;

    static $global_keywords = null;
    global $post, $wpdb;

    $manual_stop_list = get_post_meta($post->ID, '_sil_stop_list', true);
    $local_blacklist = $manual_stop_list ? array_map('trim', explode(',', strtolower($manual_stop_list))) : [];

    $max_links = 10;
    $skip_after_h1 = 50; 
    
    $context_words = ['logic', 'ai', 'python', 'kotlin', 'java', 'jvm', 'async', 'code', 'cloud', 'data', 'api', 'rust', 'swift', 'queue']; 
    $case_sensitive_words = ['Go', 'AI', 'SaaS', 'UI', 'UX', 'JVM'];
    $bridge_words = ['vs', 'and', 'or', 'with', 'in', 'to', 'for'];
    $edge_stop_words = ['the', 'a', 'an', 'and', 'but', 'or', 'for', 'with', 'at', 'by', 'from', 'to', 'in', 'on', 'is', 'it', 'this', 'that', 'of', 'vs'];

    if ($global_keywords === null) {
        $global_keywords = get_transient('sil_lsi_index_v33');
        if (false === $global_keywords) {
            $global_keywords = [];
            $results = $wpdb->get_results("SELECT t.name, p.ID FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID WHERE tt.taxonomy IN ('post_tag', 'category') AND p.post_status = 'publish' ORDER BY p.post_date DESC");
            if ($results) {
                foreach ($results as $row) { $global_keywords[trim($row->name)] = get_permalink($row->ID); }
                uksort($global_keywords, function($a, $b) { return mb_strlen($b) - mb_strlen($a); });
            }
            set_transient('sil_lsi_index_v33', $global_keywords, DAY_IN_SECONDS);
        }
    }

    $active_keywords = [];
    foreach ($global_keywords as $kw => $url) {
        $low_kw = strtolower($kw);
        if (in_array($low_kw, $local_blacklist) || in_array($low_kw, ['vs', 'a', 'the', 'is'])) continue;
        if (stripos($content, $kw) !== false) $active_keywords[$kw] = $url;
    }

    $total = 0; $used_urls = []; $linked_now = []; $words_h1 = 1000; $last_pos = -6; $skip = false;
    $current_url = untrailingslashit(get_permalink($post));
    $parts = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

    $all_kw_regex = [];
    foreach($active_keywords as $kw => $u) $all_kw_regex[] = preg_quote($kw, '/');
    if (empty($all_kw_regex)) return $content;
    $master_pattern = '(' . implode('|', $all_kw_regex) . ')';

    foreach ($parts as $i => $part) {
        if ($total >= $max_links) break;
        if (isset($part[0]) && $part[0] === '<') {
            if (preg_match('/^<([^\/!\s>]+)/', $part, $tag)) { if (in_array(strtolower($tag[1]), ['a', 'code', 'pre', 'h1', 'h2', 'h3', 'table', 'blockquote'])) $skip = true; }
            if (preg_match('/^<\/([^>]+)>/', $part, $tag)) { if (strtolower($tag[1]) === 'h1') $words_h1 = 0; $skip = false; }
            continue;
        }
        if ($skip || empty(trim($part))) continue;

        $words_h1 += count(preg_split('/\s+/u', strip_tags($part), -1, PREG_SPLIT_NO_EMPTY));
        if ($words_h1 < $skip_after_h1) continue;

        // 1. BRIDGE LOGIC (Python vs Java) - With strict check for sentence start
        $bridge_regex = '/(?<![.!?])(?<!^)\b(' . $master_pattern . '\s+(?:' . implode('|', $bridge_words) . ')\s+' . $master_pattern . ')\b(?![.!?])/ui';
        $parts[$i] = preg_replace_callback($bridge_regex, function($m) use ($active_keywords, $current_url, &$total, &$used_urls, &$last_pos, $i, &$linked_now) {
            $kw_found = $m[2]; 
            $target_url = '';
            foreach($active_keywords as $name => $u) { if (strcasecmp($name, $kw_found) === 0) { $target_url = $u; break; } }
            if (!$target_url || untrailingslashit($target_url) === $current_url || isset($used_urls[$target_url]) || $total >= 10) return $m[0];

            $total++; $used_urls[$target_url] = true; $linked_now[] = $m[1]; $last_pos = $i;
            return '<a href="'.esc_url($target_url).'">'.$m[1].'</a>';
        }, $parts[$i], 1);

        // 2. CONTEXT LOGIC - With strict check for sentence start
        foreach ($active_keywords as $kw => $url) {
            if ($total >= $max_links || untrailingslashit($url) === $current_url || isset($used_urls[$url])) continue;
            if (($i - $last_pos) < 4) continue; 

            $q_kw = preg_quote($kw, '/');
            $mode = in_array($kw, $case_sensitive_words) ? '' : 'i';
            
            // CRITICAL FIX: (?<!^)(?<![.!?]\s) — blocks linking the start of sentence/paragraph
            $pattern = in_array(strtolower($kw), $context_words) 
                ? '/(?<!^)(?<![.!?]\s)\b(\w+\s+' . $q_kw . '\s+\w+|\w+\s+\w+\s+' . $q_kw . '|' . $q_kw . '\s+\w+\s+\w+)\b(?![.!?])/u' . $mode
                : '/(?<!^)(?<![.!?]\s)\b(' . $q_kw . ')\b(?![.!?])/u' . $mode;

            $parts[$i] = preg_replace_callback($pattern, function($m) use ($url, $kw, &$total, &$used_urls, &$last_pos, $i, &$linked_now, $edge_stop_words) {
                if ($total >= 10) return $m[0];
                $words = preg_split('/\s+/u', $m[1], -1, PREG_SPLIT_NO_EMPTY);
                
                while (!empty($words) && in_array(strtolower(trim($words[0])), $edge_stop_words)) { array_shift($words); }
                while (!empty($words) && in_array(strtolower(trim(end($words))), $edge_stop_words)) { array_pop($words); }
                
                $phrase = (!empty($words)) ? implode(' ', $words) : $kw;
                if (mb_strlen($phrase) < 3 || in_array(strtolower($phrase), $edge_stop_words)) return $m[0];

                $total++; $used_urls[$url] = true; $linked_now[] = $phrase; $last_pos = $i;
                return '<a href="'.esc_url($url).'">'.$phrase.'</a>';
            }, $parts[$i], 1);
        }
    }
    update_post_meta($post->ID, '_sil_linked_log', $linked_now);
    return implode('', $parts);
}

// Admin UI: Logger and Stop List
add_action('add_meta_boxes', function() {
    add_meta_box('sil_v378_logger', 'SIL by Krun Dev: Context Linker Logger', function($post) {
        $logs = get_post_meta($post->ID, '_sil_linked_log', true);
        $stop_list = get_post_meta($post->ID, '_sil_stop_list', true);
        echo '<div style="margin-bottom:12px;"><label><strong>Stop List:</strong></label><input type="text" name="sil_stop_list" value="'.esc_attr($stop_list).'" style="width:100%;" placeholder="e.g. java, python, logic"></div>';
        echo '<label><strong>Injected Anchors:</strong></label>';
        if (empty($logs)) { 
            echo '<p style="color:#999; font-style:italic;">No links detected on this page.</p>'; 
        } else {
            echo '<div style="background:#f9f9f9; border:1px solid #ddd; padding:8px; max-height:150px; overflow:auto;">';
            foreach ((array)$logs as $log) echo '<code>' . esc_html($log) . '</code><br>';
            echo '</div>';
        }
        echo '<p style="font-size:11px; color:#bbb; margin-top:10px;">Developer: <a href="http://krun.pro/" target="_blank">krun.pro</a></p>';
    }, ['post', 'page'], 'side', 'high');
});

add_action('save_post', function($post_id) {
    if (isset($_POST['sil_stop_list'])) update_post_meta($post_id, '_sil_stop_list', sanitize_text_field($_POST['sil_stop_list']));
    delete_transient('sil_lsi_index_v33');
});

add_filter('the_content', 'silwt_internal_links_posts_only', 20);
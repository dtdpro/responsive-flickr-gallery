<?php
/*
   Plugin Name: DtD Flickr Gallery
   Plugin URI: https://github.com/dtdpro/responsive-flickr-gallery
   Description: DtD Flickr Gallery is a simple, fast and light plugin to create a responsive gallery of your Flickr photos on your WordPress enabled website.  Provides a simple yet customizable way to create Flickr galleries in a responsive theme.
   Version: 1.0.0
   Author: DtD Productions
   Author URI: http://www.dtdpro.com
   License: GPLv3 or later
   Copyright 2014 DtD Productions
   
   Forked from Responsive Flickr Gallery
   Copyright 2013, 2014 Lars Schenk (email : info@lars-schenk.de)

   Forked from: Awesome Flickr Gallery 3.3.6
   Copyright 2011 Ronak Gandhi (email : ronak.gandhi@ronakg.com)

   This file is part of the tTD Responsive Flickr Gallery.

   Responsive Flickr Gallery is free software: you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   Responsive Flickr Gallery is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License
   along with Responsive Flickr Gallery.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'afgFlickr/afgFlickr.php';
require_once 'rfg_admin_settings.php';
require_once 'rfg_libs.php';

function rfg_enqueue_cbox_scripts()
{
    wp_enqueue_script('jquery');
    wp_enqueue_script('rfg_colorbox_script', BASE_URL . "/colorbox/jquery.colorbox-min.js", array('jquery'));
    wp_enqueue_script('rfg_colorbox_js', BASE_URL . "/colorbox/mycolorbox.js", array('jquery'));
}

function rfg_enqueue_cbox_styles()
{
    wp_enqueue_style('rfg_colorbox_css', BASE_URL . "/colorbox/colorbox.css");
}

function rfg_enqueue_styles()
{
    wp_enqueue_style('rfg_css', BASE_URL . "/rfg.css");
}

$enable_colorbox = get_option('rfg_slideshow_option') == 'colorbox';

if (!is_admin()) {
    /* Short code to load Responsive Flickr Gallery plugin.  Detects the word
     * [RFG_gallery] in posts or pages and loads the gallery.
     */
    add_shortcode('RFG_gallery', 'rfg_display_gallery');
    add_filter('widget_text', 'do_shortcode', 11);

    $galleries = get_option('rfg_galleries');
    foreach ($galleries as $gallery) {
        if ($gallery['slideshow_option'] == 'colorbox') {
            $enable_colorbox = true;
            break;
        }
    }

    if ($enable_colorbox) {
        add_action('wp_print_scripts', 'rfg_enqueue_cbox_scripts');
        add_action('wp_print_styles', 'rfg_enqueue_cbox_styles');
    }

    add_action('wp_print_styles', 'rfg_enqueue_styles');
}

add_action('wp_head', 'add_rfg_headers');

function add_rfg_headers()
{
    echo "<style type=\"text/css\">" . get_option('rfg_custom_css') . "</style>";
}

function rfg_return_error_code($rsp)
{
    return $rsp['message'];
}

/* Main function that loads the gallery. */
function rfg_display_gallery($atts)
{
    global $size_heading_map, $rfg_text_color_map, $pf;

    if (!get_option('rfg_pagination')) update_option('rfg_pagination', 'on');

    extract(shortcode_atts(array('id' => '0'), $atts));

    $ad_displayed = false;
    $cur_page = 1;
    $cur_page_url = $_SERVER["REQUEST_URI"];

    preg_match("/afg{$id}_page_id=(?P<page_id>\d+)/", $cur_page_url, $matches);

    if ($matches) {
        $cur_page = ($matches['page_id']);
        $match_pos = strpos($cur_page_url, "afg{$id}_page_id=$cur_page") - 1;
        $cur_page_url = substr($cur_page_url, 0, $match_pos);
        if (function_exists('qtrans_convertURL')) {
            $cur_page_url = qtrans_convertURL($cur_page_url);
        }
    }

    if (strpos($cur_page_url, '?') === false) $url_separator = '?';
    else $url_separator = '&';

    $galleries = get_option('rfg_galleries');
    $gallery = $galleries[$id];

    $api_key = get_option('rfg_api_key');
    $user_id = get_option('rfg_user_id');
    $disable_slideshow = (get_rfg_option($gallery, 'slideshow_option') == 'disable');
    $slideshow_option = get_rfg_option($gallery, 'slideshow_option');

    $per_page = get_rfg_option($gallery, 'per_page');
    $sort_order = get_rfg_option($gallery, 'sort_order');
    $photo_size = get_rfg_option($gallery, 'photo_size');
    $photo_title = get_rfg_option($gallery, 'captions');
    $photo_descr = get_rfg_option($gallery, 'descr');
    $bg_color = get_rfg_option($gallery, 'bg_color');
    $columns = get_rfg_option($gallery, 'columns');
    $gallery_width = get_rfg_option($gallery, 'width');
    $pagination = get_rfg_option($gallery, 'pagination');
    $cache_ttl = get_rfg_option($gallery, 'cache_ttl');

    // set min width for responsiveness
    $img_cell_min_width = 0;
    if ($photo_size == "_s") $img_cell_min_width = 85; 
    else if ($photo_size == "_t") $img_cell_min_width = 110;
    else if ($photo_size == "_m") $img_cell_min_width = 250;
    else if ($photo_size == "NULL") $img_cell_min_width = 320; // shrink it a bit to allow two columns (original value: 510)

    $photoset_id = null;
    $gallery_id = null;
    $group_id = null;
    $tags = null;
    $popular = false;

    if (!isset($gallery['photo_source'])) $gallery['photo_source'] = 'photostream';

    if ($gallery['photo_source'] == 'photoset') $photoset_id = $gallery['photoset_id'];
    else if ($gallery['photo_source'] == 'gallery') $gallery_id = $gallery['gallery_id'];
    else if ($gallery['photo_source'] == 'group') $group_id = $gallery['group_id'];
    else if ($gallery['photo_source'] == 'tags') $tags = $gallery['tags'];
    else if ($gallery['photo_source'] == 'popular') $popular = true;
    else if ($gallery['photo_source'] == 'photosets') { $listsets = true; $photoset_id = $_GET['photoset_id']; }

    $disp_gallery = "<!-- Responsive Flickr Gallery -->";
    $disp_gallery .= "<!--" .
        " - Version - " . VERSION .
        " - User ID - " . $user_id .
        " - Photoset ID - " . (isset($photoset_id)? $photoset_id: '') .
        " - Gallery ID - " . (isset($gallery_id)? $gallery_id: '') .
        " - Group ID - " . (isset($group_id)? $group_id: '') .
        " - Tags - " . (isset($tags)? $tags: '') .
        " - Popular - " . (isset($popular)? $popular: '') .
        " - Per Page - " . $per_page .
        " - Sort Order - " . $sort_order .
        " - Photo Size - " . $photo_size .
        " - Captions - " . $photo_title .
        " - Description - " . $photo_descr .
        " - Columns - " . $columns .
        " - Background Color - " . $bg_color .
        " - Width - " . $gallery_width .
        " - Pagination - " . $pagination .
        " - Slideshow - " . $slideshow_option .
        " - Disable slideshow? - " . $disable_slideshow .
        " - Cache TTL - " . $cache_ttl .
        "-->";

    $extras = 'url_l, description, date_upload, date_taken, owner_name';

    if (isset($photoset_id) && $photoset_id) {
        $rsp_obj = $pf->photosets_getInfo($photoset_id);
        if ($pf->error_code) return rfg_error();
        $total_photos = $rsp_obj['photos'];
        if ($listsets) {
        	$listsets=false;
        	$disp_gallery .= '<h2>'.$rsp_obj['title']['_content'].'</h2>';
        }
    } elseif ($listsets) {
        $rsp_obj = $pf->photosets_getList($user_id);
        if ($pf->error_code) return rfg_error();
        $total_photos = $rsp_obj['total'];
    } elseif (isset($gallery_id) && $gallery_id) {
        $rsp_obj = $pf->galleries_getInfo($gallery_id);
        if ($pf->error_code) return rfg_error();
        $total_photos = $rsp_obj['gallery']['count_photos'];
    } elseif (isset($group_id) && $group_id) {
        $rsp_obj = $pf->groups_pools_getPhotos($group_id, null, null, null, null, 1, 1);
        if ($pf->error_code) return rfg_error();
        $total_photos = $rsp_obj['photos']['total'];
        if ($total_photos > 500) $total_photos = 500;
    } elseif (isset($tags) && $tags) {
        $rsp_obj = $pf->photos_search(array('user_id'=>$user_id, 'tags'=>$tags, 'extras'=>$extras, 'per_page'=>1));
        if ($pf->error_code) return rfg_error();
        $total_photos = $rsp_obj['photos']['total'];
    } elseif (isset($popular) && $popular) {
        $rsp_obj = $pf->photos_search(array('user_id'=>$user_id, 'sort'=>'interestingness-desc', 'extras'=>$extras, 'per_page'=>1));
        if ($pf->error_code) return rfg_error();
        $total_photos = $rsp_obj['photos']['total'];
    } else {
        $rsp_obj = $pf->people_getInfo($user_id);
        if ($pf->error_code) return rfg_error();
        $total_photos = $rsp_obj['photos']['count']['_content'];
    }
    


    $photos = get_transient('rfg_id_' . $id);
    if (DEBUG)
        $photos = null;
	if ($listsets) {
		$photos = $rsp_obj['photoset'];
	} elseif ($photos == false || $total_photos != count($photos)) {
        $photos = array();
        for ($i=1; $i<($total_photos/500)+1; $i++) {
            if ($listsets) {
                $flickr_api = 'photosets';
                $rsp_obj_total = $pf->photosets_getList($user_id);
                if ($pf->error_code) return rfg_error();
            } elseif ($photoset_id) {
                $flickr_api = 'photoset';
                $rsp_obj_total = $pf->photosets_getPhotos($photoset_id, $extras, null, 500, $i);
                if ($pf->error_code) return rfg_error();
            } elseif ($gallery_id) {
                $flickr_api = 'photos';
                $rsp_obj_total = $pf->galleries_getPhotos($gallery_id, $extras, 500, $i);
                if ($pf->error_code) return rfg_error();
            } elseif ($group_id) {
                $flickr_api = 'photos';
                $rsp_obj_total = $pf->groups_pools_getPhotos($group_id, null, null, null, $extras, 500, $i);
                if ($pf->error_code) return rfg_error();
            } elseif ($tags) {
                $flickr_api = 'photos';
                $rsp_obj_total = $pf->photos_search(array('user_id'=>$user_id, 'tags'=>$tags, 'extras'=>$extras, 'per_page'=>500, 'page'=>$i));
                if ($pf->error_code) return rfg_error();
            } elseif ($popular) {
                $flickr_api = 'photos';
                $rsp_obj_total = $pf->photos_search(array('user_id'=>$user_id, 'sort'=>'interestingness-desc', 'extras'=>$extras, 'per_page'=>500, 'page'=>$i));
                if ($pf->error_code) return rfg_error();
            } else {
                $flickr_api = 'photos';
                if (get_option('rfg_flickr_token')) $rsp_obj_total = $pf->people_getPhotos($user_id, array('extras' => $extras, 'per_page' => 500, 'page' => $i));
                else $rsp_obj_total = $pf->people_getPublicPhotos($user_id, null, $extras, 500, $i);
                if ($pf->error_code) return rfg_error();
            }
            $photos = array_merge($photos, $rsp_obj_total[$flickr_api]['photo']);
        }
        if (!DEBUG)
            set_transient('rfg_id_' . $id, $photos, 60 * 60 * 24 * $cache_ttl);
    }

    if (($total_photos % $per_page) == 0) $total_pages = (int)($total_photos / $per_page);
    else $total_pages = (int)($total_photos / $per_page) + 1;

    if ($gallery_width == 'auto') $gallery_width = 100;
    $text_color = isset($rfg_text_color_map[$bg_color])? $rfg_text_color_map[$bg_color]: '';
    $disp_gallery .= "<div class='rfg-gallery custom-gallery-{$id}' id='rfg-{$id}' style='background-color:{$bg_color}; width:$gallery_width%; color:{$text_color}; border-color:{$bg_color};'>";

    $disp_gallery .= "<div class='rfg-table' style='width:100%'>";

    $photo_count = 1;
    $column_width = (int)($gallery_width/$columns)-2; // -2 as a quck fix to make it work with theme baylys.

    if (!$popular && $sort_order != 'flickr') {
        if ($sort_order == 'random')
            shuffle($photos);
        else
            usort($photos, $sort_order);
    }

    if ($disable_slideshow) {
        $class = '';
        $rel = '';
        $click_event = '';
    } else {
        if ($slideshow_option == 'colorbox') {
            $class = "class='afgcolorbox'";
            $rel = "rel='example4{$id}'";
            $click_event = "";
        } elseif ($slideshow_option == 'flickr') {
            $class = "";
            $rel = "";
            $click_event = "target='_blank'";
        }
    }

    if ($photo_size == '_s') {
        $photo_width = "width='75'";
        $photo_height = "height='75'";
    } else {
        $photo_width = '';
        $photo_height = '';
    }

    $rand_pos = 0;

    $i = 0;
    while ($i < count($photos)) {
        $photo = $photos[$i];
        $p_title = esc_attr($photo['title']);
        $p_description = esc_attr($photo['description']['_content']);

        $p_description = preg_replace("/\n/", "<br />", $p_description);

        if ($listsets) {
        	$photo_url = rfg_get_photoset_url($photo['farm'],  $photo['server'],$photo['primary'],$photo['secret'],$photo_size);
        	$p_title = $photo['title']['_content'];
        } else {
        	$photo_url = rfg_get_photo_url($photo['farm'],  $photo['server'],$photo['id'],$photo['secret'],$photo_size);
        }

        if ($slideshow_option != 'none') {
            if (isset($photo['url_l'])? $photo['url_l']: '') {
                $photo_page_url = $photo['url_l'];
            } else {
                $photo_page_url = rfg_get_photo_url(
                    $photo['farm'], 
                    $photo['server'],
                    $photo['id'],
                    $photo['secret'],
                    '_z'
                );
            }

            if ($photoset_id)
                $photo['owner'] = $user_id;

            $photo_title_text = $p_title;
            //	$photo_title_text .= ' <a style="margin-left:10px; font-size:0.8em;" href="http://www.flickr.com/photos/' . $photo['owner'] . '/' . $photo['id'] . '/" target="_blank">@flickr</a>';

            $photo_title_text = esc_attr($photo_title_text);

            if ($slideshow_option == 'flickr') {
                $photo_page_url = "http://www.flickr.com/photos/" . $photo['owner'] . "/" . $photo['id'];
            }
        }

        if (($photo_count <= $per_page * $cur_page) && ($photo_count > $per_page * ($cur_page - 1))) {
            $disp_gallery .= "\n<div class='rfg-cell' style='min-width: ${img_cell_min_width}px; width:${column_width}%;'>\n";
            
            $pid_len = strlen($photo['id']);

            if ($slideshow_option != 'none') {
                if ($listsets) $disp_gallery .= "  <a href='{$cur_page_url}{$url_separator}photoset_id={$photo['id']}' title='{$photo['title']}'>";
            	else $disp_gallery .= "  <a $class $rel $click_event href='{$photo_page_url}' title='{$photo['title']}'>";
            }

            $disp_gallery .= "<img class='rfg-img' title='{$photo['title']}' src='{$photo_url}' alt='{$photo_title_text}'/>";

            if ($slideshow_option != 'none')
                $disp_gallery .= "</a>\n";

            if ($size_heading_map[$photo_size] && $photo_title == 'on') {
                if ($group_id || $gallery_id)
                    $owner_title = "- by <a href='http://www.flickr.com/photos/{$photo['owner']}/' target='_blank'>{$photo['ownername']}</a>";
                else
                    $owner_title = '';

                $disp_gallery .= "<div class='rfg-title' style='font-size:{$size_heading_map[$photo_size]}'>{$p_title} $owner_title</div>";
            }

            if ($photo_descr == 'on' && $photo_size != '_s' && $photo_size != '_t') {
                $disp_gallery .= "<div class='rfg-description'>" .
                    $photo['description']['_content'] . "</div>";
            }
            

            $disp_gallery .= "</div>\n"; // rfg-cell
            $disp_gallery .= "<!-- cur_page $cur_page -- photo_count $photo_count -->\n";
        } else {
            if ($pagination == 'on' && $slideshow_option != 'none') {
                $photo_url = '';
                $photo_src_text = "";

                $disp_gallery .= "<a style='display:none' $class $rel $click_event href='$photo_page_url'" .
                    " title='{$photo['title']}'>" .
                    " <img class='rfg-img' alt='{$photo_title_text}' $photo_src_text width='75' height='75'></a> ";
            }
        }
        $photo_count += 1;
        $i += 1;
    }
    $disp_gallery .= '</div>';

    // Pagination
    if ($pagination == 'on' && $total_pages > 1) {
        $disp_gallery .= "<div class='rfg-pagination'>";
        $disp_gallery .= "<br /><br />";
        if ($cur_page == 1) {
            $disp_gallery .="<font class='rfg-page'>&nbsp;&#171; prev&nbsp;</font>&nbsp;&nbsp;&nbsp;&nbsp;";
            $disp_gallery .="<font class='rfg-cur-page'> 1 </font>&nbsp;";
        } else {
            $prev_page = $cur_page - 1;
            $disp_gallery .= "<a class='rfg-page' href='{$cur_page_url}{$url_separator}afg{$id}_page_id=$prev_page#rfg-{$id}' title='Prev Page'>&nbsp;&#171; prev </a>&nbsp;&nbsp;&nbsp;&nbsp;";
            $disp_gallery .= "<a class='rfg-page' href='{$cur_page_url}{$url_separator}afg{$id}_page_id=1#rfg-{$id}' title='Page 1'> 1 </a>&nbsp;";
        }
        if ($cur_page - 2 > 2) {
            $start_page = $cur_page - 2;
            $end_page = $cur_page + 2;
            $disp_gallery .= " ... ";
        } else {
            $start_page = 2;
            $end_page = 6;
        }
        for ($count = $start_page; $count <= $end_page; $count += 1) {
            if ($count > $total_pages) break;
            if ($cur_page == $count)
                $disp_gallery .= "<font class='rfg-cur-page'>&nbsp;{$count}&nbsp;</font>&nbsp;";
            else
                $disp_gallery .= "<a class='rfg-page' href='{$cur_page_url}{$url_separator}afg{$id}_page_id={$count}#rfg-{$id}' title='Page {$count}'>&nbsp;{$count} </a>&nbsp;";
        }

        if ($count < $total_pages) $disp_gallery .= " ... ";
        if ($count <= $total_pages)
            $disp_gallery .= "<a class='rfg-page' href='{$cur_page_url}{$url_separator}afg{$id}_page_id={$total_pages}#rfg-{$id}' title='Page {$total_pages}'>&nbsp;{$total_pages} </a>&nbsp;";
        if ($cur_page == $total_pages) $disp_gallery .= "&nbsp;&nbsp;&nbsp;<font class='rfg-page'>&nbsp;next &#187;&nbsp;</font>";
        else {
            $next_page = $cur_page + 1;
            $disp_gallery .= "&nbsp;&nbsp;&nbsp;<a class='rfg-page' href='{$cur_page_url}{$url_separator}afg{$id}_page_id=$next_page#rfg-{$id}' title='Next Page'> next &#187; </a>&nbsp;";
        }
        $disp_gallery .= "<br />({$total_photos} Photos)";
        $disp_gallery .= "</div>";
    }
    $disp_gallery .= "</div>";
    // disable default tool tip
    $disp_gallery .=  <<<EOD
 <script type='text/javascript'>
   jQuery('.rfg-img').removeAttr("title");
   jQuery('.afgcolorbox').removeAttr("title");
 </script>
<!-- /Responsive Flickr Gallery -->
EOD;
    return $disp_gallery;
}

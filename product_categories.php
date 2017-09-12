<?php
    // ------------- Rentman Product Category Functions ------------- \\

    # Names of (sub)folders have been imported
    # Create the categories in Wordpress
    function add_category($folder){
        # Insert WooCommerce Term
        $categoryIDs = get_option('plugin-rentmanIDs', array()); // get global category array

        # Check if the array contains the ID
        if ($categoryIDs[$folder[0]] == null)
            $receive_term = '';
        else
            $receive_term = get_term($categoryIDs[$folder[0]], 'product_cat');

        # Add the category if it does not exist yet
        if ($receive_term == ''){
            wp_insert_term(
                $folder[1], # Name of term
                'product_cat', # Taxonomy
                array(
                    'slug' => $folder[0]
                )
            );
            $receive_term = get_term_by('slug', $folder[0], 'product_cat');
            add_term_meta($receive_term->term_id, "source", 'Rentman'); // add Rentman as source
            $categoryIDs = get_option('plugin-rentmanIDs', array()); // get global category array
            $categoryIDs[$folder[0]] = $receive_term->term_id; // add the product ID to array
            update_option('plugin-rentmanIDs', $categoryIDs); // update the array
        }
    }

    # Go through all the imported product categories and set the right parents
    function set_parents($folder){
        $categoryIDs = get_option('plugin-rentmanIDs', array()); // get global category array
        $parent_term = get_term($categoryIDs[$folder[2]], 'product_cat');
        $this_term = get_term($categoryIDs[$folder[0]], 'product_cat');
        $parent_term_id = $parent_term->term_id;
        $this_term_id = $this_term->term_id;
        # Edit the term
        wp_update_term(
            $this_term_id, # Name of term
            'product_cat', # Taxonomy
            array(
                'parent' => $parent_term_id
            )
        );
    }

    # Arranges folder data from API response
    function arrange_folders($parsed){
        $folderList = array();
        $switch = true;
        $counter = 1;
		$lastkey = end($parsed['response']['items']['Folder'])['data']['id'];

        # Continue until the last folder in the API response has been finished
        while ($switch){
            $name = $parsed['response']['items']['Folder'][$counter]['data']['naam'];
            $id = $parsed['response']['items']['Folder'][$counter]['data']['id'];
            $parent = $parsed['response']['items']['Folder'][$counter]['data']['parent'];
            array_push($folderList, array($id, $name, $parent));
            if ($id == $lastkey) # Last folder in the array has been reached
                $switch = false;
            else
                $counter++;
        }
        return $folderList;
    }

    # Delete all irrelevant product subcategories in the webshop (in other words, the empty ones)
    function remove_empty_categories(){
        $args = array(
            'taxonomy'     => 'product_cat',
            'orderby'      => 'name',
            'show_count'   => 0,
            'pad_counts'   => 0,
            'hierarchical' => 1,
            'title_li'     => '',
            'hide_empty'   => 0
        );
        $all_categories = get_categories($args);
        foreach($all_categories as $cat){
            if($cat->category_parent == 0){
                # Check the children of the category
                $children = get_categories(array('taxonomy' => 'product_cat', 'parent' => $cat->term_id, 'hide_empty' => 0));
                $itemCount = max($cat->count, displayChildren($children));
                # Delete term if the category and all children are empty
                if ($itemCount == 0){
                    $source = get_term_meta($cat->term_id, 'source', true);
                    if ($source == 'Rentman'){ # Check if the category originates from Rentman
                        $categoryIDs = get_option('plugin-rentmanIDs', array());
                        $keychain = array_search($cat->term_id, $categoryIDs);
                        unset($categoryIDs[$keychain]);
                        update_option('plugin-rentmanIDs', $categoryIDs); // update the array
                        wp_delete_term($cat->term_id, 'product_cat');
                    }
                }
            }
        }
    }

    # Check item count for children
    function displayChildren($children){
        $itemCount = 0;
        foreach ($children as $child){
            $itemCount = max($itemCount, $child->count);
            # Recursively keep checking the size of all children
            $grandchildren = get_categories(array('taxonomy' => 'product_cat', 'parent' => $child->term_id, 'hide_empty' => 0));
            $thiscount = displayChildren($grandchildren);
            $itemCount = max($itemCount, $thiscount);
            # Delete term if the category and all children are empty
            if (max($thiscount, $child->count) == 0){
                $source = get_term_meta($child->term_id, 'source', true);
                if ($source == 'Rentman') { # Check if the category originates from Rentman
                    $categoryIDs = get_option('plugin-rentmanIDs', array());
                    $keychain = array_search($child->term_id, $categoryIDs);
                    unset($categoryIDs[$keychain]);
                    update_option('plugin-rentmanIDs', $categoryIDs); // update the array
                    wp_delete_term($child->term_id, 'product_cat');
                }
            }
        }
        return $itemCount;
    }
?>
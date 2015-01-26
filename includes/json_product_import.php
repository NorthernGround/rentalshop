<?php 
class JSON_Product_Import {
	//public $category_list = array();
	public $object_list = array(); // Key: Rentman ID, Value: WooCommerce ID
	public $new_rentman_category_ids = array(); // List of Categories form the latest import

	public function delete_all_products() {
		Debug::dump('!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
		Debug::dump('!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
		Debug::dump('All products are being deleted');
		Debug::dump('!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
		Debug::dump('!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
		// Delete all current products
		$all_products_arguments = array(
			'post_type' => array('product', 'attachment'),
			'posts_per_page' => -1,
			'post_status' => 'any');
		$all_products = get_posts( $all_products_arguments );
		foreach ($all_products as $product) {
			$this->delete_post_attachments($product->ID);
			wp_delete_post($product->ID, true);
		}
	}

	public function delete_post_attachments($post) {
		$attachments = get_attached_media('', $post);
		foreach ($attachments as $attachment) {
			wp_delete_attachment($attachment->ID, true);
		}
	}

	public function delete_all_products_safe($products) {
		// Delete products that are no longer needed
		$all_products_arguments = array(
			'post_type' => array('product'),
			'posts_per_page' => -1,
			'post_status' => 'any');
		$all_products = get_posts( $all_products_arguments );
		$new_skus = array();
		foreach($products as $product) {
			$new_skus[] = $product["id"];
		}
		foreach ($all_products as $current_product) {
			$sku = get_post_meta($current_product->ID, "_sku");
			$sku = $sku[0];
			if ( ! in_array($sku, $new_skus)) {
				$this->delete_post_attachments($current_product->ID);
				$a = wp_delete_post($current_product->ID, true);
			}
		}
	}

	public function add_category($category, $rentman_ids, $current_categories, $parent = 0) {
		$id = intval($category["id"]);
		$name = $category['naam'];
		$children = $category["children"];
		$this->new_rentman_category_ids[] = $id;

		if ( in_array( $id, $rentman_ids ) ) {
			$wc_id = array_search( $id , $rentman_ids );

			// Item already exists
			if ( ! $this->product_name_exists( $name, $current_categories ) ) {
				// Name changed
				if ($wc_id === false ) {
					Debug::dump('Error in category lookup');
				} else {
					$new_data = array( 'name' => $name, 'slug' => $name );
					wp_update_term( $wc_id, 'product_cat',  $new_data );
					// Debug::dump($name);
					// Debug::dump($current_categories);
				}
			}
			if (! empty ( $children ) ) {
				$term_id = $wc_id;
				foreach ($children as $child) {
					$this->add_category($child, $rentman_ids, $current_categories, $term_id);
				}
			}
		} else {
			// Item must be new
			$insertion = wp_insert_term($category["naam"], "product_cat", array('parent' => $parent, 'slug' => $category["naam"]));
			if (is_wp_error($insertion)) {
				$term_id = $insertion->error_data["term_exists"];
			} else {
				$term_id = $insertion["term_id"];
			}
			$rentman_categories = get_option( 'rentman_categories' , array() );
			$rentman_categories[ $term_id ] = $id;
			update_option( 'rentman_categories', $rentman_categories );
			if (! empty ( $children ) ) {
				foreach ($children as $child) {
					$this->add_category($child, $rentman_ids, $current_categories, $term_id);
				}
			}
		}
	}

	public function import_categories_safe( $new_categories ) {
		$rentman_categories = get_option( 'rentman_categories' , array() );
		$current_categories = get_terms('product_cat', array('hide_empty' => 0));
		//Debug::dump($current_categories);
		
		// Add the new categories
		foreach ( $new_categories as $new_category ) {
			$this->add_category( $new_category, $rentman_categories, $current_categories );
		}

		//Debug::dump($rentman_categories);
		// Check if any old categories can be deleted
		foreach ( $current_categories as $current_category ) {
			if ( array_key_exists($current_category->term_id, $rentman_categories)) {
				$rentman_id = $rentman_categories[$current_category->term_id];
				if ( ! in_array( $rentman_id, $this->new_rentman_category_ids ) ) {
					// Delete the category
					$p = wp_delete_term($current_category->term_id, 'product_cat');
					// Delete the option key
					$rentman_categories = get_option( 'rentman_categories' , array() );
					unset( $rentman_categories[ $current_category->term_id ] );
					update_option( 'rentman_categories', $rentman_categories );
				}
			} else {
				// Delete the category
				$p = wp_delete_term($current_category->term_id, 'product_cat');
				// Delete the option key
				$rentman_categories = get_option( 'rentman_categories' , array() );
				unset( $rentman_categories[ $current_category->term_id ] );
				update_option( 'rentman_categories', $rentman_categories );
			}
		}
	}

	private function product_name_exists($needle, $haystack) {
		foreach ( $haystack as $hay ) {
			if ( $hay->name == htmlspecialchars($needle) ) {
				return true;
			}
		}
		return false;
	}

	public function delete_all_categories() {
		$categories = get_terms('product_cat', array('hide_empty' => 0));
		foreach ($categories as $category) {
			wp_delete_term($category->term_id, 'product_cat');
		}
	}

	public function generate_object_list() {
		if ( ! empty ( $this->object_list ) ) {
			return ;
		}
		$object_list = array();
		$all_products_arguments = array(
			'post_type' => array('product'),
			'posts_per_page' => -1,
			'post_status' => 'any');
		$all_products = get_posts( $all_products_arguments );
		foreach($all_products as $product) {
			$sku = get_post_meta($product->ID, "_sku");
			if (!empty($sku)) {
				$sku = $sku[0];
				$object_list[$sku] = $product->ID;
			}
		}
		$this->object_list = $object_list;
	}

	public function add_cross_sells($products) {
		$this->generate_object_list();
		$object_list = $this->object_list;
		foreach ($products as $product) {
			$values = array();
			foreach($product as $cross_sell) {
				// Parent ID is also an element but not an array
				if (is_array($cross_sell)) {
					// Find the parent object in the object list
					$values[] = $object_list[$cross_sell["materiaal"]];
				}
			}
			if (array_key_exists($product["parent"], $object_list)) {
				$parent = $object_list[$product["parent"]];
				delete_post_meta($parent, "_crosssell_ids");
				update_post_meta($parent,
					"_crosssell_ids",
					$values);
			}
		}
	}

	private function add_attachments($files, $attachment_post_id) {
		global $rentman;
		foreach ($files as $i => $file) {
			// Get the path to the upload directory.
			$wp_upload_dir = wp_upload_dir();
			
			// Get the file referenced and save it
			$folder = $wp_upload_dir['path'] . "/";
			if ( ! is_dir($folder)) {
				mkdir($folder);
			}

			$url = $file['url'];

			try {
			    $fh = fopen($url, 'r');

			    if (! $fh) {
			        throw new Exception("Could not open the file");
			    }

				$file_save_success = file_put_contents(
					$folder . $i . $file['naam'], 
					$fh
					);
				if ($file_save_success === false) {
					Debug::dump('ERROR: Attachment upload failed');
					return new WP_Error('upload_failed', "Attachment upload failed");
				}

				// $filename should be the path to a file in the upload directory.
				$filename = $wp_upload_dir[ 'subdir' ] . '/' . $i . $file["naam"] ;

				// The ID of the post this attachment is for.
				$parent_post_id = $attachment_post_id;

				// Check the type of tile. We'll use this as the 'post_mime_type'.
				$filetype = wp_check_filetype( basename( $filename ), null );

				// Prepare an array of post data for the attachment.
				$attachment = array(
					'guid'           => $wp_upload_dir['url'] . '/' . basename( $filename ), 
					'post_mime_type' => $filetype['type'],
					'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
					'post_content'   => '',
					'post_status'    => 'inherit'
				);

				$file_absolute_path = $wp_upload_dir['path'] . '/' . $i . $file["naam"];

				// Insert the attachment
				$attach_id = wp_insert_attachment( $attachment, $file_absolute_path, $parent_post_id );

				// Make sure that this file is included, as wp_generate_attachment_metadata() depends on it.
				require_once( ABSPATH . 'wp-admin/includes/image.php' );

				// Generate the metadata for the attachment, and update the database record.
				$attach_data = wp_generate_attachment_metadata( $attach_id, $file_absolute_path );
				wp_update_attachment_metadata( $attach_id, $attach_data );

				if ($file['inShop']) {
					set_post_thumbnail( $parent_post_id, $attach_id );
				}
			}
			catch (Exception $e) {
			    echo "Error (File: ".$e->getFile().", line ".
			          $e->getLine()."): ".$e->getMessage();
				continue;
			}

		}
	}

	public function import_products($decoded) {
		// Check if the decoded JSON is a list of lists
		if (NULL == $decoded || !(is_array($decoded[0]))) {
			return new WP_Error('no_json', 'No JSON data was found');
		}

		$all_products_arguments = array(
			'post_type' => array('product', 'attachment'),
			'posts_per_page' => -1,
			'post_status' => 'any');
		$all_products = get_posts( $all_products_arguments );
		$existing_skus = array();
		foreach($all_products as $existing_product) {
			$sku = get_post_meta($existing_product->ID, "_sku");
			if ($sku) {
				$existing_skus[] = $sku[0];
			}
		}

		$dates_modified = get_option( 'rentman_products_modified ');

		// Add the new products
		foreach ($decoded as $product) {
			$product_id = intval( $product["id"] );
			if ( is_array($dates_modified) &&
					array_key_exists( $product_id , $dates_modified ) && 
					strtotime($product['modified']) > $dates_modified[$product_id]['modified'] ) {
				// Product is updated
				$post_id = $dates_modified[$product_id]["wc_id"];
				if ( empty( $product["shopDescriptionLong"] ) ) {
					$content = "Geen informatie beschikbaar";
				} else {
					$content = $product["shopDescriptionLong"];
				}
				wp_update_post( array(
					"ID" => $post_id,
					"post_title" => $product["naam"],
					"post_content" => $content,
					"post_excerpt" => $product["shopDescriptionShort"],
					"post_status" => "publish",
					"post_type" => "product"
					));

				wp_delete_attachment($post_id, true);
				if ($post_id == 17372) {
					Debug::dump($product);
				}
				if ( isset( $product["files"] ) ) {
					$files_upload_success = $this->add_attachments( $product["files"], $post_id );
					if (is_wp_error($files_upload_success)) {
						return $files_upload_success;
						Debug::dump('File upload failed');
					}
				}

				update_post_meta( $post_id, '_visibility', 'visible' );
				update_post_meta( $post_id, '_stock_status', 'instock');
				update_post_meta( $post_id, 'total_sales', '0');
				update_post_meta( $post_id, '_downloadable', 'no');
				update_post_meta( $post_id, '_virtual', 'no');
				update_post_meta( $post_id, '_regular_price', $product["verhuurprijs"] );
				update_post_meta( $post_id, '_purchase_note', "" );
				update_post_meta( $post_id, '_featured', "no" );
				update_post_meta( $post_id, '_weight', "" );
				update_post_meta( $post_id, '_length', "" );
				update_post_meta( $post_id, '_width', "" );
				update_post_meta( $post_id, '_height', "" );
				update_post_meta( $post_id, '_sku', $product["id"]);
				update_post_meta( $post_id, '_product_attributes', array());
				update_post_meta( $post_id, '_sale_price_dates_from', "" );
				update_post_meta( $post_id, '_sale_price_dates_to', "" );
				update_post_meta( $post_id, '_price', $product["verhuurprijs"] );
				update_post_meta( $post_id, '_sold_individually', "" );
				update_post_meta( $post_id, '_manage_stock', "no" );
				update_post_meta( $post_id, '_backorders', "no" );
				update_post_meta( $post_id, '_stock', "aantal" );

				$dates_modified[$product_id]["modified"] = time();
				update_option( 'rentman_products_modified', $dates_modified );

				$category_lookup = get_option( 'rentman_categories', array() );
				$category_slug = array_search( intval($product["parent"]) , $category_lookup );
				$result2 = wp_set_object_terms($post_id, array($category_slug), "product_cat");

			} else if ($product["naam"] && ! in_array( $product_id, $existing_skus ) ) {
				if ( empty( $product["shopDescriptionLong"] ) ) {
					$content = "Geen informatie beschikbaar";
				} else {
					$content = $product["shopDescriptionLong"];
				}
				$new_product = array(
					"post_name" => $product["naam"],
					"post_title" => $product["naam"],
					"post_content" => $content,
					"post_excerpt" => $product["shopDescriptionShort"],
					"post_status" => "publish",
					"post_type" => "product"
					);
				$post_id = wp_insert_post($new_product, TRUE);
				if (is_wp_error( $post_id )) {
					//Debug::dump($post_id);
					//Debug::dump($product);
					$error_string = $post_id->get_error_message();
					echo '<div id="message" class="error"><p>' . $error_string . '</p></div>';
				} else {
					// Add attachments
					if ( isset( $product["files"] ) ) {
						$files_upload_success = $this->add_attachments( $product["files"], $post_id );
						if (is_wp_error($files_upload_success)) {
							return $files_upload_success;
							Debug::dump('File upload failed');
						}
					}
					// Add object to object list
					//$this->object_list[$product["id"]] = $post_id;
					// Set all other data
					wp_set_object_terms($post_id, 'rentable', 'product_type');
					add_post_meta($post_id, 'true', 'rentman_imported');
					update_post_meta( $post_id, '_visibility', 'visible' );
					update_post_meta( $post_id, '_stock_status', 'instock');
					update_post_meta( $post_id, 'total_sales', '0');
					update_post_meta( $post_id, '_downloadable', 'no');
					update_post_meta( $post_id, '_virtual', 'no');
					update_post_meta( $post_id, '_regular_price', $product["verhuurprijs"] );
					update_post_meta( $post_id, '_purchase_note', "" );
					update_post_meta( $post_id, '_featured', "no" );
					update_post_meta( $post_id, '_weight', "" );
					update_post_meta( $post_id, '_length', "" );
					update_post_meta( $post_id, '_width', "" );
					update_post_meta( $post_id, '_height', "" );
					update_post_meta( $post_id, '_sku', $product["id"]);
					update_post_meta( $post_id, '_product_attributes', array());
					update_post_meta( $post_id, '_sale_price_dates_from', "" );
					update_post_meta( $post_id, '_sale_price_dates_to', "" );
					update_post_meta( $post_id, '_price', $product["verhuurprijs"] );
					update_post_meta( $post_id, '_sold_individually', "" );
					update_post_meta( $post_id, '_manage_stock', "no" );
					update_post_meta( $post_id, '_backorders', "no" );
					update_post_meta( $post_id, '_stock', "aantal" );
					// Set category
					$category_lookup = get_option( 'rentman_categories', array() );
					$category_slug = array_search( intval($product["parent"]) , $category_lookup );
					$result2 = wp_set_object_terms($post_id, array($category_slug), "product_cat");

					$dates_modified[$product["id"]] = array();
					$dates_modified[$product["id"]]["wc_id"] = $post_id;
					$dates_modified[$product["id"]]["modified"] = time();
					update_option( 'rentman_products_modified', $dates_modified );
				}
			}
		}
	}
}
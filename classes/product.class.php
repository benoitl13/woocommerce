<?php
/**
 * Product Class
 * 
 * The WooCommerce product class handles individual product data.
 *
 * @class woocommerce_product
 * @package		WooCommerce
 * @category	Class
 * @author		WooThemes
 */
class woocommerce_product {
	
	var $id;
	var $exists;
	var $attributes;
	var $children;
	var $post;
	var $sku;
	var $price;
	var $visibility;
	var $stock;
	var $stock_status;
	var $backorders;
	var $manage_stock;
	var $sale_price;
	var $regular_price;
	var $weight;
	var $tax_status;
	var $tax_class;
	var $upsell_ids;
	var $crosssell_ids;
	var $product_type;
	var $total_stock;
	
	
	/**
	 * Loads all product data from custom fields
	 *
	 * @param   int		$id		ID of the product to load
	 */
	function woocommerce_product( $id ) {
		
		$this->id = $id;

		$product_custom_fields = get_post_custom( $this->id );
		
		// Define the data we're going to load: Key => Default value
		$load_data = array(
			'sku'			=> $this->id,
			'price' 		=> '',
			'visibility'	=> 'hidden',
			'stock'			=> 0,
			'stock_status'	=> 'instock',
			'backorders'	=> 'no',
			'manage_stock'	=> 'no',
			'sale_price'	=> '',
			'regular_price' => '',
			'weight'		=> '',
			'tax_status'	=> 'taxable',
			'tax_class'		=> '',
			'upsell_ids'	=> array(),
			'crosssell_ids' => array()
		);
		
		// Load the data from the custom fields
		foreach ($load_data as $key => $default) :
			if (isset($product_custom_fields[$key][0]) && $product_custom_fields[$key][0]!=='') :
				$this->$key = $product_custom_fields[$key][0];
			else :
				$this->$key = $default;
			endif;
		endforeach;
		
		// Load serialised data, unserialise twice to fix WP bug
		if (isset($product_custom_fields['product_attributes'][0])) $this->attributes = maybe_unserialize( maybe_unserialize( $product_custom_fields['product_attributes'][0] )); else $this->attributes = array();		
						
		// Get product type
		$terms = wp_get_object_terms( $id, 'product_type' );
		if (!is_wp_error($terms) && $terms) :
			$term = current($terms);
			$this->product_type = $term->slug; 
		else :
			$this->product_type = 'simple';
		endif;
		
		$this->get_children();
		
		// total_stock
		$this->total_stock = $this->stock;
		if (sizeof($this->children)>0) foreach ($this->children as $child) :
			if (isset($child->product->variation_has_stock)) :
				if ($child->product->variation_has_stock) :
					$this->total_stock += $child->product->stock;
				endif;
			else :
				$this->total_stock += $child->product->stock;
			endif;
		endforeach;
		
		if ($product_custom_fields) :
			$this->exists = true;		
		else :
			$this->exists = false;	
		endif;
	}
	
	/**
     * Get SKU (Stock-keeping unit) - product uniqe ID
     * 
     * @return mixed
     */
    function get_sku() {
        return $this->sku;
    }
    
    
	/** Returns the product's children */
	function get_children() {
		
		if (!is_array($this->children)) :
		
			$this->children = array();
			
			if ($this->is_type('variable')) $child_post_type = 'product_variation'; else $child_post_type = 'product';
		
			if ( $children_products =& get_children( 'post_parent='.$this->id.'&post_type='.$child_post_type.'&orderby=menu_order&order=ASC' ) ) :

				if ($children_products) foreach ($children_products as $child) :
					
					if ($this->is_type('variable')) :
						$child->product = &new woocommerce_product_variation( $child->ID );
					else :
						$child->product = &new woocommerce_product( $child->ID );
					endif;
					
				endforeach;
				$this->children = (array) $children_products;
			endif;
			
		endif;
		
		return (array) $this->children;
	}

	/**
	 * Reduce stock level of the product
	 *
	 * @param   int		$by		Amount to reduce by
	 */
	function reduce_stock( $by = 1 ) {
		if ($this->managing_stock()) :
			$this->stock = $this->stock - $by;
			$this->total_stock = $this->total_stock - $by;
			update_post_meta($this->id, 'stock', $this->stock);
			
			// Out of stock attribute
			if (!$this->is_in_stock()) update_post_meta($this->id, 'stock_status', 'outofstock');
			
			return $this->stock;
		endif;
	}
	
	/**
	 * Increase stock level of the product
	 *
	 * @param   int		$by		Amount to increase by
	 */
	function increase_stock( $by = 1 ) {
		if ($this->managing_stock()) :
			$this->stock = $this->stock + $by;
			$this->total_stock = $this->total_stock + $by;
			update_post_meta($this->id, 'stock', $this->stock);
			
			// Out of stock attribute
			if ($this->is_in_stock()) update_post_meta($this->id, 'stock_status', 'instock');
			
			return $this->stock;
		endif;
	}
	
	/**
	 * Checks the product type
	 *
	 * @param   string		$type		Type to check against
	 */
	function is_type( $type ) {
		if (is_array($type) && in_array($this->product_type, $type)) return true;
		elseif ($this->product_type==$type) return true;
		return false;
	}
	
	/** Returns whether or not the product has any child product */
	function has_child () {
		return sizeof($this->children) ? true : false;
	}
	
	/** Returns whether or not the product post exists */
	function exists() {
		if ($this->exists) return true;
		return false;
	}
	
	/** Returns whether or not the product is taxable */
	function is_taxable() {
		if ($this->tax_status=='taxable') return true;
		return false;
	}
	
	/** Returns whether or not the product shipping is taxable */
	function is_shipping_taxable() {
		if ($this->tax_status=='taxable' || $this->tax_status=='shipping') return true;
		return false;
	}
	
	/** Get the product's post data */
	function get_post_data() {
		if (empty($this->post)) :
			$this->post = get_post( $this->id );
		endif;
		
		return $this->post;
	}
	
	/** Get the title of the post */
	function get_title () {
		$this->get_post_data();
		return apply_filters('woocommerce_product_title', get_the_title($this->post->ID), $this);
	}

	
	/** Get the add to url */
	function add_to_cart_url() {
		
		if ($this->is_type('variable')) :
			$url = add_query_arg('add-to-cart', 'variation');
			$url = add_query_arg('product', $this->id, $url);
		elseif ( $this->has_child() ) :
			$url = add_query_arg('add-to-cart', 'group');
			$url = add_query_arg('product', $this->id, $url);
		else :
			$url = add_query_arg('add-to-cart', $this->id);
		endif;
		
		$url = woocommerce::nonce_url( 'add_to_cart', $url );
		return $url;
	}
	
	/** Returns whether or not the product is stock managed */
	function managing_stock() {
		if (get_option('woocommerce_manage_stock')=='yes') :
			if (isset($this->manage_stock) && $this->manage_stock=='yes') return true;
		endif;
		return false;
	}
	
	/** Returns whether or not the product is in stock */
	function is_in_stock() {
		if ($this->managing_stock()) :
			if (!$this->backorders_allowed()) :
				if ($this->total_stock==0 || $this->total_stock<0) :
					return false;
				else :
					if ($this->stock_status=='instock') return true;
					return false;
				endif;
			else :
				if ($this->stock_status=='instock') return true;
				return false;
			endif;
		endif;
		if ($this->stock_status=='instock') return true;
		return false;
	}
	
	/** Returns whether or not the product can be backordered */
	function backorders_allowed() {
		if ($this->backorders=='yes' || $this->backorders=='notify') return true;
		return false;
	}
	
	/** Returns whether or not the product needs to notify the customer on backorder */
	function backorders_require_notification() {
		if ($this->backorders=='notify') return true;
		return false;
	}
	
	/**
     * Returns number of items available for sale.
     * 
     * @return int
     */
    function get_stock_quantity() {
        return (int)$this->stock;
    }

	/** Returns whether or not the product has enough stock for the order */
	function has_enough_stock( $quantity ) {
		
		if ($this->backorders_allowed()) return true;
		
		if ($this->stock >= $quantity) :
			return true;
		endif;
		
		return false;
		
	}
	
	/** Returns the availability of the product */
	function get_availability() {
	
		$availability = "";
		$class = "";
		
		if (!$this->managing_stock()) :
			if ($this->is_in_stock()) :
				//$availability = __('In stock', 'woothemes'); /* Lets not bother showing stock if its not managed and is available */
			else :
				$availability = __('Out of stock', 'woothemes');
				$class = 'out-of-stock';
			endif;
		else :
			if ($this->is_in_stock()) :
				if ($this->total_stock > 0) :
					$availability = __('In stock', 'woothemes');
					
					if ($this->backorders_allowed()) :
						if ($this->backorders_require_notification()) :
							$availability .= ' &ndash; '.$this->stock.' ';
							$availability .= __('available', 'woothemes');
							$availability .= __(' (backorders allowed)', 'woothemes');
						endif;
					else :
						$availability .= ' &ndash; '.$this->stock.' ';
						$availability .= __('available', 'woothemes');
					endif;
					
				else :
					
					if ($this->backorders_allowed()) :
						if ($this->backorders_require_notification()) :
							$availability = __('Available on backorder', 'woothemes');
						else :
							$availability = __('In stock', 'woothemes');
						endif;
					else :
						$availability = __('Out of stock', 'woothemes');
						$class = 'out-of-stock';
					endif;
					
				endif;
			else :
				if ($this->backorders_allowed()) :
					$availability = __('Available on backorder', 'woothemes');
				else :
					$availability = __('Out of stock', 'woothemes');
					$class = 'out-of-stock';
				endif;
			endif;
		endif;
		
		return array( 'availability' => $availability, 'class' => $class);
	}
	
	/** Returns whether or not the product is featured */
	function is_featured() {
		if (get_post_meta($this->id, 'featured', true)=='yes') return true;
		return false;
	}
	
	/** Returns whether or not the product is visible */
	function is_visible() {
	
		// Out of stock visibility
		if (get_option('woocommerce_hide_out_of_stock_items')=='yes') :
			if (!$this->is_in_stock()) return false;
		endif;
		
		// visibility setting
		if ($this->visibility=='hidden') return false;
		if ($this->visibility=='visible') return true;
		if ($this->visibility=='search' && is_search()) return true;
		if ($this->visibility=='search' && !is_search()) return false;
		if ($this->visibility=='catalog' && is_search()) return false;
		if ($this->visibility=='catalog' && !is_search()) return true;
	}
	
	/** Returns whether or not the product is on sale */
	function is_on_sale() {
	
		if ( $this->has_child() ) :
		
			$onsale = false;
			
			foreach ($this->children as $child) :
				if ( $child->product->sale_price==$child->product->price ) :
					return true;
				endif;
			endforeach;
			
		else :
		
			if ( $this->sale_price && $this->sale_price==$this->price ) :
				return true;
			endif;
		
		endif;

		return false;
	}
	
	/** Returns the product's weight */
	function get_weight() {
		if ($this->weight) return $this->weight;
	}
	
	/** Returns the product's price */
	function get_price() {
		
		return $this->price;
	
	}
	
	/** Returns the price (excluding tax) */
	function get_price_excluding_tax() {
		
		$price = $this->price;
			
		if (get_option('woocommerce_prices_include_tax')=='yes') :
		
			if ( $rate = $this->get_tax_base_rate() ) :
				
				if ( $rate>0 ) :
					
					$_tax = &new woocommerce_tax();

					$tax_amount = $_tax->calc_tax( $price, $rate, true );
					
					$price = $price - $tax_amount;
					
					// Round
					$price = round( $price * 100 ) / 100;

					// Format
					$price = number_format($price, 2, '.', '');
					
				endif;
				
			endif;
		
		endif;
		
		return $price;
	}
	
	/** Returns the base tax rate */
	function get_tax_base_rate() {
		
		if ( $this->is_taxable() && get_option('woocommerce_calc_taxes')=='yes') :
			
			$_tax = &new woocommerce_tax();
			$rate = $_tax->get_shop_base_rate( $this->tax_class );
			
			return $rate;
			
		endif;
		
	}
	
	/** Returns the price in html format */
	function get_price_html() {
		$price = '';
		if ( $this->has_child() ) :
			
			$min_price = '';
			$max_price = '';
			
			foreach ($this->children as $child) :
				$child_price = $child->product->get_price();
				if ($child_price<$min_price || $min_price == '') $min_price = $child_price;
				if ($child_price>$max_price || $max_price == '') $max_price = $child_price;
			endforeach;
			
			$price .= '<span class="from">' . __('From: ', 'woothemes') . '</span>' . woocommerce_price($min_price);		
		elseif ($this->is_type('variable')) :
		
			$price .= '<span class="from">' . __('From: ', 'woothemes') . '</span>' . woocommerce_price($this->get_price());	
		
		else :
			if ($this->price) :
				if ($this->is_on_sale() && isset($this->regular_price)) :
					$price .= '<del>'.woocommerce_price( $this->regular_price ).'</del> <ins>'.woocommerce_price($this->get_price()).'</ins>';
				else :
					$price .= woocommerce_price($this->get_price());
				endif;
			elseif ($this->price === '' ) :
				return false;
			elseif ($this->price === '0' ) :
				$price = __('Free!', 'woothemes');      
			endif;
		endif;
		return $price;
	}
	
	/** Returns the upsell product ids */
	function get_upsells() {
		return (array) maybe_unserialize( $this->upsell_ids );
	}
	
	/** Returns the crosssell product ids */
	function get_cross_sells() {
		return (array) maybe_unserialize( $this->crosssell_ids );
	}
	
	/** Returns the product categories */
	function get_categories( $sep = ', ', $before = '', $after = '' ) {
		return get_the_term_list($this->id, 'product_cat', $before, $sep, $after);
	}
	
	/** Returns the product tags */
	function get_tags( $sep = ', ', $before = '', $after = '' ) {
		return get_the_term_list($this->id, 'product_tag', $before, $sep, $after);
	}
	
	/** Get and return related products */
	function get_related( $limit = 5 ) {
		global $wpdb, $all_post_ids;
		// Related products are found from category and tag
		$tags_array = array(0);
		$cats_array = array(0);
		$tags = '';
		$cats = '';
		
		// Get tags
		$terms = wp_get_post_terms($this->id, 'product_tag');
		foreach ($terms as $term) {
			$tags_array[] = $term->term_id;
		}
		$tags = implode(',', $tags_array);
		
		$terms = wp_get_post_terms($this->id, 'product_cat');
		foreach ($terms as $term) {
			$cats_array[] = $term->term_id;
		}
		$cats = implode(',', $cats_array);

		$q = "
			SELECT p.ID
			FROM $wpdb->term_taxonomy AS tt, $wpdb->term_relationships AS tr, $wpdb->posts AS p, $wpdb->postmeta AS pm
			WHERE 
				p.ID != $this->id
				AND p.post_status = 'publish'
				AND p.post_date_gmt < NOW()
				AND p.post_type = 'product'
				AND pm.meta_key = 'visibility'
				AND pm.meta_value IN ('visible', 'catalog')
				AND pm.post_id = p.ID
				AND
				(
					(
						tt.taxonomy ='product_cat'
						AND tt.term_taxonomy_id = tr.term_taxonomy_id
						AND tr.object_id  = p.ID
						AND tt.term_id IN ($cats)
					)
					OR 
					(
						tt.taxonomy ='product_tag'
						AND tt.term_taxonomy_id = tr.term_taxonomy_id
						AND tr.object_id  = p.ID
						AND tt.term_id IN ($tags)
					)
				)
			GROUP BY tr.object_id
			ORDER BY RAND()
			LIMIT $limit;";
 
		$related = $wpdb->get_col($q);
		
		return $related;
	}
	
	/** Returns product attributes */
	function get_attributes() {
		return $this->attributes;
	}
	
	/** Returns whether or not the product has any attributes set */
	function has_attributes() {
		if (isset($this->attributes) && sizeof($this->attributes)>0) :
			foreach ($this->attributes as $attribute) :
				if ($attribute['visible'] == 'yes') return true;
			endforeach;
		endif;
		return false;
	}
	
	/** Lists a table of attributes for the product page */
	function list_attributes() {
		$attributes = $this->get_attributes();
		if ($attributes && sizeof($attributes)>0) :
			
			echo '<table cellspacing="0" class="shop_attributes">';
			$alt = 1;
			foreach ($attributes as $attribute) :
				if ($attribute['visible'] == 'no') continue;
				$alt = $alt*-1;
				echo '<tr class="';
				if ($alt==1) echo 'alt';
				echo '"><th>'.wptexturize($attribute['name']).'</th><td>';
				
				if (is_array($attribute['value'])) $attribute['value'] = implode(', ', $attribute['value']);
				
				echo wpautop(wptexturize($attribute['value']));
				
				echo '</td></tr>';
			endforeach;
			echo '</table>';

		endif;
	}
	
	/**
     * Return an array of attributes used for variations, as well as their possible values
     * 
     * @return two dimensional array of attributes and their available values
     */   
    function get_available_attribute_variations() {
       
        if (!$this->is_type('variable') || !$this->has_child()) return array();
        
        $attributes = $this->get_attributes();
        
        if(!is_array($attributes)) return array();
        
        $available_attributes = array();
        $children = $this->get_children();
        
        foreach ($attributes as $attribute) {
            if ($attribute['variation'] !== 'yes') continue;

            $values = array();
            $taxonomy = 'tax_'.sanitize_title($attribute['name']);

            foreach ($children as $child) {
                /* @var $variation woocommerce_product_variation */
                $variation = $child->product;

                if ($variation instanceof woocommerce_product_variation) {
                	
                	if ($variation->variation->post_status != 'publish') continue; // Disabled
                	
                    $attributes = $variation->get_variation_attributes();

                    if (is_array($attributes)) {
                        foreach ($attributes as $name => $value) {
                            if ($name == $taxonomy) {
                                $values[] = $value;
                            }
                        }
                    }
                }
            }
            
            // empty value indicates that all options for given attribute are available
            if(in_array('', $values)) {
                $options = $attribute['value'];

                if (!is_array($options)) {
                    $options = explode(',', $options);
                }
                
                $values = $options;
            }
              
            $available_attributes[$attribute['name']] = array_unique($values);
        }
        
        return $available_attributes;
    }

}
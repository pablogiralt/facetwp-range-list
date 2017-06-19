<?php
/*
Plugin Name: FacetWP - Range List
Description: Range list facet type
Version: 0.1
Author: FacetWP, LLC
Author URI: https://facetwp.com/
GitHub URI: facetwp/facetwp-range-list
*/

defined( 'ABSPATH' ) or exit;

/**
 * FacetWP registration hook
 */
add_filter( 'facetwp_facet_types', function ( $facet_types ) {
	$facet_types['range_list'] = new FacetWP_Facet_Range_List_Addon();

	return $facet_types;
} );


/**
 * Range List facet class
 */
class FacetWP_Facet_Range_List_Addon {

	function __construct() {
		$this->label = __( 'Range List', 'fwp' );
	}


	/**
	 * Load the available choices
	 */
	function load_values( $params ) {
		global $wpdb;

		$facet = $params['facet'];

		// Apply filtering (ignore the facet's current selection)
		if ( isset( FWP()->or_values ) && ( 1 < count( FWP()->or_values ) || ! isset( FWP()->or_values[ $facet['name'] ] ) ) ) {
			$post_ids = array();
			$or_values = FWP()->or_values; // Preserve the original
			unset( $or_values[ $facet['name'] ] );

			$counter = 0;
			foreach ( $or_values as $name => $vals ) {
				$post_ids = ( 0 == $counter ) ? $vals : array_intersect( $post_ids, $vals );
				$counter++;
			}

			// Return only applicable results
			$post_ids = array_intersect( $post_ids, FWP()->unfiltered_post_ids );
		}
		else {
			$post_ids = FWP()->unfiltered_post_ids;
		}

		$post_ids     = empty( $post_ids ) ? array( 0 ) : $post_ids;
		$where_clause = ' AND post_id IN (' . implode( ',', $post_ids ) . ')';
		$from_clause  = $wpdb->prefix . 'facetwp_index f';

		$from_clause  = apply_filters( 'facetwp_facet_from', $from_clause, $facet );
		$where_clause = apply_filters( 'facetwp_facet_where', $where_clause, $facet );

		$sql = "
        SELECT f.facet_value, f.post_id
        FROM $from_clause
        WHERE f.facet_name = '{$facet['name']}' $where_clause";

		$results = $wpdb->get_results( $sql, ARRAY_A );
		$output  = array();
		sort( $params['facet']['levels'] );
		// build groups.
		foreach ( $params['facet']['levels'] as $level => $start ) {
			$end = null;
			if ( isset( $params['facet']['levels'][ $level + 1 ] ) ) {
				$end = $params['facet']['levels'][ $level + 1 ] - 1;
			}

			$output[] = array(
				'start'   => $start,
				'end'     => $end,
				'counter' => $this->get_counts( $results, $start, $end ),
			);
		}

		return $output;
	}

	/**
	 * Filter out irrelevant choices
	 */
	function get_counts( $results, $start, $end ) {
		$count = array();

		foreach ( $results as $result ) {
			if ( $result['facet_value'] >= $start ) {
				if ( is_null( $end ) || $result['facet_value'] <= $end ) {
					$count[] = $result;//+= 1;// (int) $result['counter'];
				}
			}
		}

		return $count;
	}

	/**
	 * Generate the facet HTML
	 */
	function render( $params ) {

		$output          = '';
		$values          = (array) $params['values'];
		$selected_values = (array) $params['selected_values'];
		if ( ! empty( $selected_values ) ) {
			$selected_value = $selected_values[0];
		}

		foreach ( $values as $key => $result ) {

			$display = $params['facet']['prefix'] . $this->formatNumber( $result['start'], $params['facet']['format'] ) . $params['facet']['suffix'];
			$value    = $result['start'];
			if ( ! empty( $result['end'] ) ) {
			    $value .=  '-' . $result['end'];
				$display .= ' to ' . $params['facet']['prefix'] . $this->formatNumber( $result['end'], $params['facet']['format'] ) . $params['facet']['suffix'];
			}else{
			    $display .= '+';
            }
			$selected = ( $value === $selected_value ) ? ' checked' : '';
			$selected .= ( 0 == count( $result['counter'] ) && '' == $selected ) ? ' disabled' : '';
			$output   .= '<div class="facetwp-radio' . $selected . '" data-value="' . esc_attr( $value ) . '">';
			$output   .= esc_html( $display ) . ' <span class="facetwp-counter">(' . count( $result['counter'] ) . ')</span>';
			$output   .= '</div>';
		}

		return $output;
	}


	/**
	 * Filter the query based on selected values
	 */
	function filter_posts( $params ) {
		global $wpdb;

		$facet = $params['facet'];
		$selected_values = $params['selected_values'];
		$selected_values = array_pop( $selected_values );
		$selected_values = explode('-', $selected_values );
		$selected_values = array_map('floatval', $selected_values );
		$sql = "
        SELECT DISTINCT post_id FROM {$wpdb->prefix}facetwp_index
        WHERE facet_name = '{$facet['name']}' AND facet_value > $selected_values[0]";
		if( !empty( $selected_values[1] ) ){
			$sql .= " AND facet_value < $selected_values[1] ";
		}

		return facetwp_sql( $sql, $facet );
	}


	/**
	 * Output any admin scripts
	 */
	function admin_scripts() {
		?>
        <script>
            (function ($) {
                wp.hooks.addAction('facetwp/load/range_list', function ($this, obj) {
                    $this.find('.facet-source').val(obj.source);
                    $this.find('.facet-orderby').val(obj.orderby);
                    $this.find('.facet-prefix').val(obj.prefix);
                    $this.find('.facet-suffix').val(obj.suffix);
                    $this.find('.facet-format').val(obj.format);
                    var wrap = $this.find('.range-list-add-level-wrap');
                    for (var l = 0; l < obj.levels.length; l++) {
                        create_label($this, obj.levels[l]);
                    }
                    if (0 === obj.levels.length) {
                        create_label($this);
                    }
                    $this.find('.range-list-level:first .button').remove();
                });

                wp.hooks.addFilter('facetwp/save/range_list', function ($this, obj) {
                    obj['source'] = $this.find('.facet-source').val();
                    obj['orderby'] = $this.find('.facet-orderby').val();
                    obj['prefix'] = $this.find('.facet-prefix').val();
                    obj['suffix'] = $this.find('.facet-suffix').val();
                    obj['format'] = $this.find('.facet-format').val();
                    obj['hierarchical'] = 'yes'; // locked
                    obj['operator'] = 'or'; // locked
                    obj['levels'] = [];
                    $this.find('.facet-start-level').each(function () {
                        obj['levels'].push(this.value);
                    });

                    return obj;
                });

                function create_label($table, val) {
                    var $target = $table.find('.range-list-add-level-wrap');
                    var clone = $('#range-list-tpl').html();

                    var num_labels = $table.find('.range-list-level').length;
                    clone = clone.replace('{n}', num_labels);

                    var $tpl = $(clone);

                    if (val) {
                        $tpl.find('.facet-start-level').val(val);
                    }

                    $tpl.insertBefore($target);
                }

                $(document).on('click', '.range-list-add-level', function () {
                    var $table = $(this).closest('.facet-fields');
                    create_label($table);
                });

                $(document).on('click', '.range-list-remove-level', function () {
                    $(this).closest('.range-list-level').remove();
                });
            })(jQuery);
        </script>
        <script type="text/html" id="range-list-tpl">
            <tr class="range-list-level">
                <td>
                    <span class="facetwp-changeme"><?php _e( "Range {n}", 'fwp' ); ?></span>:
                    <div class="facetwp-tooltip">
                        <span class="icon-question">?</span>
                        <div class="facetwp-tooltip-content">
                            Customize this range.
                        </div>
                    </div>
                </td>
                <td>
                    <input type="number" class="facet-start-level" value=""
                           placeholder="<?php esc_attr_e( 'Starting Value', 'fwp' ); ?>"
                           style="width: 125px;"/>
                    <input type="button" class="button range-list-remove-level"
                           style="margin: 1px;"
                           value="<?php esc_attr_e( 'Remove', 'fwp' ); ?>"/>
                </td>
            </tr>
        </script>
		<?php
	}


	/**
	 * Output any front-end scripts
	 */
	function front_scripts() {
		?>
        <script>
            (function ($) {

                wp.hooks.addAction('facetwp/refresh/range_list', function ($this, facet_name) {
                    var selected_values = [];
                    $this.find('.facetwp-radio.checked').each(function () {
                        selected_values.push($(this).attr('data-value'));
                    });
                    FWP.facets[facet_name] = selected_values;
                });

                wp.hooks.addFilter('facetwp/selections/range_list', function (output, params) {
                    var choices = [];
                    $.each(params.selected_values, function (idx, val) {
                        var choice = params.el.find('.facetwp-radio[data-value="' + val + '"]').clone();
                        choice.find('.facetwp-counter').remove();
                        choices.push({
                            value: val,
                            label: choice.text()
                        });
                    });
                    return choices;
                });

                $(document).on('click', '.facetwp-type-range_list .facetwp-radio:not(.disabled)', function () {
                    var is_checked = $(this).hasClass('checked');
                    $(this).closest('.facetwp-facet').find('.facetwp-radio').removeClass('checked');
                    if (!is_checked) {
                        $(this).addClass('checked');
                    }
                    FWP.autoload();
                });
            })(jQuery);
        </script>
		<?php
	}


	/**
	 * Output admin settings HTML
	 */
	function settings_html() {
		$thousands = FWP()->helper->get_setting( 'thousands_separator' );
		$decimal   = FWP()->helper->get_setting( 'decimal_separator' );
		?>
        <tr>
            <td><?php _e( 'Sort by', 'fwp' ); ?>:</td>
            <td>
                <select class="facet-orderby">
                    <option value="count"><?php _e( 'Highest Count', 'fwp' ); ?></option>
                    <option value="display_value"><?php _e( 'Display Value', 'fwp' ); ?></option>
                    <option value="raw_value"><?php _e( 'Raw Value', 'fwp' ); ?></option>
                </select>
            </td>
        </tr>
        <tr>
            <td>
				<?php _e( 'Prefix', 'fwp' ); ?>:
                <div class="facetwp-tooltip">
                    <span class="icon-question">?</span>
                    <div class="facetwp-tooltip-content"><?php _e( 'Text that appears before each slider value', 'fwp' ); ?></div>
                </div>
            </td>
            <td><input type="text" class="facet-prefix" value=""/></td>
        </tr>
        <tr>
            <td>
				<?php _e( 'Suffix', 'fwp' ); ?>:
                <div class="facetwp-tooltip">
                    <span class="icon-question">?</span>
                    <div class="facetwp-tooltip-content"><?php _e( 'Text that appears after each slider value', 'fwp' ); ?></div>
                </div>
            </td>
            <td><input type="text" class="facet-suffix" value=""/></td>
        </tr>
        <tr>
            <td>
				<?php _e( 'Format', 'fwp' ); ?>:
                <div class="facetwp-tooltip">
                    <span class="icon-question">?</span>
                    <div class="facetwp-tooltip-content"><?php _e( 'The number format', 'fwp' ); ?></div>
                </div>
            </td>
            <td>
                <select class="facet-format">
					<?php if ( '' != $thousands ) : ?>
                        <option value="0,0">5<?php echo $thousands; ?>280
                        </option>
                        <option value="0,0.0">5<?php echo $thousands; ?>
                            280<?php echo $decimal; ?>4
                        </option>
                        <option value="0,0.00">5<?php echo $thousands; ?>
                            280<?php echo $decimal; ?>42
                        </option>
					<?php endif; ?>
                    <option value="0">5280</option>
                    <option value="0.0">5280<?php echo $decimal; ?>4</option>
                    <option value="0.00">5280<?php echo $decimal; ?>42</option>
                    <option value="0a">5k</option>
                    <option value="0.0a">5<?php echo $decimal; ?>3k</option>
                    <option value="0.00a">5<?php echo $decimal; ?>28k</option>
                </select>
            </td>
        </tr>
        <tr class="range-list-add-level-wrap">
            <td></td>
            <td>
                <input type="button"
                       class="range-list-add-level button button-small"
                       style="width: 200px;"
                       value="<?php esc_attr_e( 'Add Range', 'fwp' ); ?>"/>
            </td>
        </tr>
		<?php
	}

	/**
	 * port of the toFixed() method
	 */
	function toFixed( $value, $precision ) {
		$power = pow( 10, $precision );
		$value = round( $value * $power ) / $power;

		return round( $value, $precision );
	}
	/**
	 * Port of the format number method from nummy.js
	 */
	function formatNumber( $value, $format, $opts = '' ) {

		$negative   = false;
		$precision  = 0;
		$valueStr   = '';
		$wholeStr   = '';
		$decimalStr = '';
		$abbr       = '';

		if ( false !== strpos( $format, 'a' ) ) {
			$abs = abs( $value );
			if ( $abs >= pow( 10, 12 ) ) {
				$value = $value / pow( 10, 12 );
				$abbr  .= 't';
			} else if ( $abs < pow( 10, 12 ) && $abs >= pow( 10, 9 ) ) {
				$value = $value / pow( 10, 9 );
				$abbr  .= 'b';
			} else if ( $abs < pow( 10, 9 ) && $abs >= pow( 10, 6 ) ) {
				$value = $value / pow( 10, 6 );
				$abbr  .= 'm';
			} else if ( $abs < pow( 10, 6 ) && $abs >= pow( 10, 3 ) ) {
				$value = $value / pow( 10, 3 );
				$abbr  .= 'k';
			}
			$format = str_replace( 'a', '', $format );
		}

		// Check for decimals format
		if ( false !== strpos( $format, '.' ) ) {
			$parts     = explode( '.', $format );
			$precision = strlen( $parts[1] );

		}

		$value    = $this->toFixed( $value, $precision );
		$valueStr = (string) $value;

		// Handle $negative number
		if ( $value < 0 ) {
			$negative = true;
			$value    = abs( $value );
			$valueStr = substr( $valueStr, 1 );
		}

		$strparts   = explode( '.', $valueStr );
		$wholeStr   = ( isset( $strparts[0] ) ? $strparts[0] : '' );
		$decimalStr = ( isset( $strparts[1] ) ? $strparts[1] : '' );

		// Handle decimals
		$decimalStr = ( 0 < $precision && '' != $decimalStr ) ? '.' . $decimalStr : '';

		// Use thousands separators
		if ( false !== strpos( $format, ',' ) ) {
			$wholeStr = preg_replace( "/(\d)(?=(\d{3})+(?!\d))+/", '$1,', $wholeStr );
		}

		$output = ( $negative ? '-' : '' ) . $wholeStr . $decimalStr . $abbr;

		$output = preg_replace("/\./", '{d}',$output);
		$output = preg_replace("/\,/", '{t}',$output);
		$output = preg_replace("/{d}/", ',', $output);
		$output = preg_replace("/{t}/", ',', $output);

		return $output;
	}
}

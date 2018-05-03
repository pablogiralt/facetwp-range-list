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
            $post_ids  = array();
            $or_values = FWP()->or_values; // Preserve the original
            unset( $or_values[ $facet['name'] ] );

            $counter = 0;
            foreach ( $or_values as $name => $vals ) {
                $post_ids = ( 0 == $counter ) ? $vals : array_intersect( $post_ids, $vals );
                $counter ++;
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

        // Build groups
        foreach ( $params['facet']['levels'] as $level => $setting ) {
            $min      = $this->get_range_value( 'min', $level, 'down', $params['facet']['levels'] );
            $max      = $this->get_range_value( 'max', $level, 'up', $params['facet']['levels'] );
            $label    = ( isset( $setting['label'] ) ? $setting['label'] : null );
            $output[] = array(
                'set'     => array(
                    'min' => $min,
                    'max' => $max,
                ),
                'label'   => $label,
                'counter' => $this->get_counts( $results, $min, $max ),
            );
        }

        return $output;
    }


    /**
     * Get the lowest value
     */
    function get_range_value( $type, $level, $direction, $levels ) {
        $val = null;
        if ( ! empty( $levels[ $level ][ $type ] ) ) {
            $val = $levels[ $level ][ $type ];
        }
        elseif ( $level > 0 && $level < count( $levels ) ) {
            if ( $type === 'min' ) {
                $type = 'max';
            } else {
                $type = 'min';
            }

            if ( $direction === 'up' ) {
                $level = $level + 1;
            } else {
                $level = $level - 1;
            }

            $val = $this->get_range_value( $type, $level, $direction, $levels );
        }

        return $val;
    }


    /**
     * Filter out irrelevant choices
     */
    function get_counts( $results, $start, $end ) {
        $count = 0;

        foreach ( $results as $result ) {
            if ( $result['facet_value'] >= $start ) {
                if ( is_null( $end ) || $result['facet_value'] <= $end ) {
                    $count += 1;
                }
            }
        }

        return $count;
    }


    /**
     * Generate the facet HTML
     */
    function render( $params ) {

        $output = '';
        $values = (array) $params['values'];
        $selected_values = (array) $params['selected_values'];

        $selected_value = 0;
        if ( ! empty( $selected_values ) ) {
            $selected_value = $selected_values[0];
        }

        foreach ( $values as $key => $result ) {
            $display = $result['label'];

            if( ! empty($result['set']['min'] ) && ! empty( $result['set']['max'] ) ) {
                $auto_display = implode(' - ', $result['set'] );
                $value = implode('-', $result['set'] );
            }
            elseif ( empty( $result['set']['min'] ) && ! empty( $result['set']['max'] ) ) {
                $auto_display = 'Up to ' . $result['set']['max'];
                $value = '0-' . $result['set']['max'];
            }
            elseif ( ! empty($result['set']['min'] ) && empty( $result['set']['max'] ) ) {
                $auto_display = $result['set']['min'] . ' and up';
                $value = $result['set']['min'] . '+';
            }
            else {
                $auto_display = 'All';
            }

            if ( is_null( $display ) ) {
                $display = $auto_display;
            }

            $selected = ( $value === $selected_value ) ? ' checked' : '';
            $selected .= ( 0 == $result['counter'] && '' == $selected ) ? ' disabled' : '';
            $output .= '<div class="facetwp-radio' . $selected . '" data-value="' . esc_attr( $value ) . '">';
            $output .= esc_html( $display ) . ' <span class="facetwp-counter">(' . $result['counter'] . ')</span>';
            $output .= '</div>';
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
        $selected_values = explode( '-', $selected_values );
        $selected_values = array_map( 'floatval', $selected_values );

        $sql = "
        SELECT DISTINCT post_id FROM {$wpdb->prefix}facetwp_index
        WHERE facet_name = '{$facet['name']}' AND facet_value >= $selected_values[0]";

        if ( ! empty( $selected_values[1] ) ) {
            $sql .= " AND facet_value <= $selected_values[1] ";
        }

        return facetwp_sql( $sql, $facet );
    }


    /**
     * Output any admin scripts
     */
    function admin_scripts() {
    ?>
    <style type="text/css">
    .facet-level-row {
        padding-bottom: 10px;
    }

    .facet-level-row input[type="number"],
    .facet-level-row input[type="text"] {
        height: 28px;
    }
    </style>

    <script>
    (function ($) {
        wp.hooks.addAction('facetwp/load/range_list', function($this, obj) {
            $this.find('.facet-source').val(obj.source);

            for (var l = 0; l < obj.levels.length; l++) {
                create_label($this, obj.levels[l]);
            }
        });

        wp.hooks.addFilter('facetwp/save/range_list', function(obj, $this) {
            obj['source'] = $this.find('.facet-source').val();
            obj['operator'] = 'or'; // locked
            obj['levels'] = [];
            $this.find('.facet-level-row').each(function() {
                var level = $(this);
                var row = {
                    'min': level.find('.facet-range-list-min').val(),
                    'max': level.find('.facet-range-list-max').val(),
                    'label': level.find('.facet-range-list-label').val(),
                };
                obj['levels'].push(row);
            });

            return obj;
        });

        function create_label($table, val) {
            var clone = $('#range-list-tpl').html();
            var $tpl = $(clone);

            if (val) {
                $tpl.find('.facet-range-list-min').val(val.min);
                $tpl.find('.facet-range-list-max').val(val.max);
                $tpl.find('.facet-range-list-label').val(val.label);
            }

            if (! val || '' == val.label) {
                $tpl.attr('autoLabel', true);
            }

            $table.find('.facet-range-list').append($tpl);
        }

        function find_lowest($row) {
            var prev_row = $row.prev(),
                lower = prev_row.find('.facet-range-list-max'),
                val = 'Up to';

            if (prev_row.length) {
                val = lower.val().length ? parseFloat( lower.val() ) : find_lowest(prev_row);
            }

            return val;
        }

        function find_highest($row) {
            var next_row = $row.next(),
                upper = next_row.find('.facet-range-list-min'),
                val = 'and Up';

            if (next_row.length) {
                val = upper.val().length ? parseFloat( upper.val() ) : find_highest(next_row);
            }

            return val;
        }

        function update_labels($this) {
            $this.find('.facet-level-row').each(function() {
                var row = $(this),
                    sep = ' ',
                    label = row.find('.facet-range-list-label'),
                    min = row.find('.facet-range-list-min').val().length ? parseFloat( row.find('.facet-range-list-min').val() ) : find_lowest(row),
                    max = row.find('.facet-range-list-max').val().length ? parseFloat( row.find('.facet-range-list-max').val() ) : find_highest(row);

                if (row.is('[autoLabel]')) {
                    if (typeof min === 'number' && typeof max === 'number') {
                        sep = ' - ';
                    }
                    if (typeof min !== 'string' || typeof max !== 'string') {
                        label.val(min + sep + max);
                    }
                    else {
                        label.val('');
                    }
                }
            })
        }

        $(document).on('click', '.facet-range-list-add', function() {
            create_label($(this).closest('.facet-fields'));
            update_labels($(this).closest('.facet-range-list'));

        });

        $(document).on('click', '.facet-range-list-remove', function() {
            $(this).closest('.facet-level-row').remove();
        });

        $(document).on('input', '.facet-range-list-label', function() {
            $(this).closest('.facet-level-row').removeAttr('autoLabel');
        });

        $(document).on('input', '.facet-range-list-min, .facet-range-list-max', function() {
            update_labels($(this).closest('.facet-range-list'));
        });
    })(jQuery);
    </script>
    <script type="text/html" id="range-list-tpl">
        <div class="facet-level-row">
            <input type="number" class="facet-range-list-min" value=""
                placeholder="<?php esc_attr_e( 'Min Value', 'fwp' ); ?>"
                style="width: 115px;"/>
            <input type="number" class="facet-range-list-max" value=""
                placeholder="<?php esc_attr_e( 'Max Value', 'fwp' ); ?>"
                style="width: 115px;"/>
            <input type="text" class="facet-range-list-label" value=""
                placeholder="<?php esc_attr_e( 'Label', 'fwp' ); ?>"
                style="width: 115px;"/>
            <input type="button" class="button facet-range-list-remove"
                style="margin: 1px;"
                value="<?php esc_attr_e( 'Remove', 'fwp' ); ?>"/>
        </div>
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
        ?>
        <tr>
            <td><?php _e( 'Ranges', 'fwp' ); ?>:</td>
            <td>
                <div class="facet-range-list"></div>
                <input type="button"
                    class="facet-range-list-add button button-small"
                    style="width: 200px;"
                    value="<?php esc_attr_e( 'Add Range', 'fwp' ); ?>"/>
            </td>
        </tr>
        <?php
    }
}

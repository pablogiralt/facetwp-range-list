<?php

class FacetWP_Facet_Range_List_Addon extends FacetWP_Facet
{

    function __construct() {
        $this->label = __( 'Range List', 'fwp' );
    }


    /**
     * Load the available choices
     */
    function load_values( $params ) {
        global $wpdb;

        $facet = $params['facet'];
        $from_clause  = $wpdb->prefix . 'facetwp_index f';
        $where_clause = $this->get_where_clause( $facet );

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
     * Output any front-end scripts
     */
    function front_scripts() {
?>
<script>

(function($) {

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
    width: 115px;
    height: 28px;
}
</style>

<script>

Vue.component('range-list', {
    props: ['facet'],
    template: `
    <div>
        <div class="facet-level-row" v-for="(row, index) in facet.levels">
            <input type="number" v-model="facet.levels[index].min" @input="updateLabels()" placeholder="Min Value" />
            <input type="number" v-model="facet.levels[index].max" @input="updateLabels()" placeholder="Max Value" />
            <input type="text" v-model="facet.levels[index].label" placeholder="Label" />
            <button @click="removeRange(index)">x</button>
        </div>
        <button @click="addRange()">Add Range</button>
    </div>
    `,
    methods: {
        addRange: function() {
            this.facet.levels.push({
                min: '',
                max: '',
                label: ''
            });
        },
        removeRange: function(index) {
            Vue.delete(this.facet.levels, index);
            this.updateLabels();
        },
        updateLabels: function() {
            for (var i = 0; i < this.facet.levels.length; i++) {
                let sep = ' ';
                let min = this.facet.levels[i].min;
                let max = this.facet.levels[i].max;
                min = min.length ? parseFloat(min) : this.findLowest(i);
                max = max.length ? parseFloat(max) : this.findHighest(i);

                if ('number' === typeof min && 'number' === typeof max) {
                    sep = ' - ';
                }
                if ('string' !== typeof min || 'string' !== typeof max) {
                    this.facet.levels[i].label = min + sep + max;
                }
                else {
                    this.facet.levels[i].label = '';
                }
            }
        },
        findLowest: function(index) {
            let val = 'Up to';

            if (0 < index) {
                let lower = this.facet.levels[index-1].max;
                val = (lower.length) ? parseFloat(lower) : this.findLowest(index-1);
            }

            return val;
        },
        findHighest: function(index) {
            let val = 'and Up';

            if (index < this.facet.levels.length-1) {
                let upper = this.facet.levels[index+1].min;
                val = (upper.length) ? parseFloat(upper) : this.findHighest(index+1);
            }

            return val;
        }
    }
});

</script>
<?php
    }


    /**
     * Output admin settings HTML
     */
    function settings_html() {
        ?>
        <div class="facetwp-row">
            <div class="facetwp-col"><?php _e( 'Ranges', 'fwp' ); ?>:</div>
            <div class="facetwp-col">
                <range-list :facet="facet"></range-list>
                <input type="hidden" class="facet-levels" value="[]" />
            </div>
        </div>
        <?php
    }
}

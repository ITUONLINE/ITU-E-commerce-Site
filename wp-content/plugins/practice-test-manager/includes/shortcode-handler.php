<?php
add_shortcode('practice_test','ptm_render_test');
function ptm_render_test($atts){
    $atts = shortcode_atts(array('test_id'=>0),$atts);
    $test_id = intval($atts['test_id']);
    if($test_id<=0){
        return '<p style="color:red;">Error: No test ID specified.</p>';
    }
    return '<div id="ptm-test" data-test-id="'.esc_attr($test_id).'">'
        . '<div class="ptm-loading">'
        . '<div class="ptm-loading__spinner"></div>'
        . '<p class="ptm-loading__text">Your test is loading</p>'
        . '</div>'
        . '</div>';
}

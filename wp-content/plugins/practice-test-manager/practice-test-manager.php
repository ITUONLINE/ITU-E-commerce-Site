<?php
/*
Plugin Name: Practice Test Manager
Description: Deliver randomized practice tests with learning feedback via AJAX.
Version: 1.2
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

// Start PHP session only on frontend (not admin/AJAX) to avoid blocking concurrent requests
add_action('init', function(){
    if (is_admin() && defined('DOING_AJAX') && DOING_AJAX) return;
    if (!session_id() && !headers_sent()) {
        session_start();
    }
});



// Enqueue scripts and styles
add_action('wp_enqueue_scripts','ptm_enqueue_scripts');
function ptm_enqueue_scripts(){
    // CSS
    wp_enqueue_style(
        'ptm-style',
        plugins_url('assets/style.css', __FILE__),
        [],
        '1.2'
    );

    // JS
    wp_enqueue_script(
        'ptm-script',
        plugins_url('assets/script.js', __FILE__),
        ['jquery'],
        '1.2',
        true
    );

    wp_localize_script('ptm-script','ptm_ajax',[
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('ptm_nonce')
    ]);
}

// Include shortcode and AJAX handlers
require_once plugin_dir_path(__FILE__).'includes/shortcode-handler.php';
require_once plugin_dir_path(__FILE__).'includes/ajax-handler.php';

if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'includes/admin-page.php';
}

// Auto-schedule crons if they're not scheduled (fixes plugin transfer without activation)
add_action('init', function () {
    if (!wp_next_scheduled('ptm_cron_add_answers')) {
        wp_schedule_event(time(), 'ptm_every_five_minutes', 'ptm_cron_add_answers');
    }
    if (!wp_next_scheduled('ptm_cron_add_questions_job')) {
        wp_schedule_event(time(), 'ptm_every_5_min', 'ptm_cron_add_questions_job');
    }
}, 20);

// 1) Add a 15‑minute schedule to WP‑Cron
add_filter( 'cron_schedules', 'ptm_add_five_min_schedule' );
function ptm_add_five_min_schedule( $s ) {
    if ( empty( $s['ptm_every_five_minutes'] ) ) {
        $s['ptm_every_five_minutes'] = [
            'interval' => 5 * 60,
            'display'  => 'PTM Every 5 Minutes',
        ];
    }
    return $s;
}

// 2) Schedule the event on plugin activation
register_activation_hook( __FILE__, 'ptm_activation_schedule' );
function ptm_activation_schedule() {
    if ( ! wp_next_scheduled( 'ptm_cron_add_answers' ) ) {
        wp_schedule_event( time(), 'ptm_every_five_minutes', 'ptm_cron_add_answers' );
    }
}

// 3) Unschedule on deactivation
register_deactivation_hook( __FILE__, 'ptm_deactivation_clear' );
function ptm_deactivation_clear() {
    $ts = wp_next_scheduled( 'ptm_cron_add_answers' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'ptm_cron_add_answers' );
    }
}

// 4) Hook the cron event to your handler
add_action( 'ptm_cron_add_answers', 'ptm_cron_add_answers_handler' );
function ptm_cron_add_answers_handler() {
    global $wpdb;

    // Fetch up to 3 questions that have zero answers
    // and belong to a “practice” test (adjust test‑table JOIN/filter to match your schema)
    $questions = $wpdb->get_results( "
        SELECT q.id AS question_id, q.test_id
        FROM wp_ptm_questions AS q
        INNER JOIN wp_ptm_practice_tests     AS t ON t.id = q.test_id
        LEFT  JOIN wp_ptm_answers   AS a ON a.question_id = q.id
        WHERE a.id IS NULL                -- no answers yet
        ORDER BY RAND() LIMIT 25
    " );

    foreach ( $questions as $row ) {
        ptm_generate_answers_for_question( (int)$row->question_id, (int)$row->test_id );
    }
}

// 5) Refactor your existing logic into a reusable helper
function ptm_generate_answers_for_question( $question_id, $test_id ) {
    global $wpdb;

    // 5a) Grab the question text
    $question = $wpdb->get_row( $wpdb->prepare(
        "SELECT question_text FROM wp_ptm_questions WHERE id = %d",
        $question_id
    ) );
    if ( ! $question ) {
        error_log( "ptm: invalid question #{$question_id}" );
        return;
    }

    // 5b) Build the system prompt
    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if ( ! $api_key ) {
        error_log( 'ptm: missing API key' );
        return;
    }

    $system = <<<EOT
You are a practice‑test generator.  For the question below, return exactly four newline‑separated answer lines, no extra text, in this format:

<answer_text>|<is_correct_flag>|<explanation_if_correct>|<explanation_if_incorrect>

EXAMPLE: Question you are to Answer

RETURN
First Anwswer Option|1|<explanation_if_correct>|
Second Answer Option|0||<explanation_if_incorrect>
Third Answer Option|0||<explanation_if_incorrect>
Fourth Answer Option|0||<explanation_if_incorrect>

Rules:
– Exactly 4 lines
- Only 1 correct answer can be provided.  
– Correct Answer is_correct_flag=1, Incorrect Answer is_correct_flag=0
– Correct line gets explanation_if_correct; incorrect lines get explanation_if_incorrect


Question: '{$question->question_text}'
EOT;

    // 5c) Call the OpenAI API
    $body = wp_json_encode( [
        'model'       => function_exists('itu_ai_model') ? itu_ai_model('practice_test') : 'gpt-4o-mini',
        'messages'    => [
            [ 'role' => 'system', 'content' => $system ],
        ],
        'temperature' => 0.7,
    ] );
    $resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => $body,
        'timeout' => 60,
    ] );
    if ( is_wp_error( $resp ) ) {
        error_log( 'ptm API error: ' . $resp->get_error_message() );
        return;
    }

    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    $raw  = trim( $data['choices'][0]['message']['content'] ?? '' );

    $log_file = WP_CONTENT_DIR . '/ptm_cron.log';
    $timestamp = date( '[Y-m-d H:i:s]' );
    error_log( $timestamp . $raw . PHP_EOL, 3, $log_file );	
	
    // strip code fences if any
    if ( preg_match( '/^```/', $raw ) ) {
        $raw = preg_replace( '/^```[A-Za-z]*\R/', '', $raw );
        $raw = preg_replace( '/\R```$/', '', $raw );
        $raw = trim( $raw );
    }

    // 5d) Expect exactly 4 lines
    $lines = preg_split( '/\r?\n/', $raw, -1, PREG_SPLIT_NO_EMPTY );
    if ( count( $lines ) !== 4 ) {
        error_log( "ptm: expected 4 answers, got " . count( $lines ) . "\n" . $raw );
        return;
    }

    // 5e) Parse and insert
foreach ( $lines as $line ) {
    // Split into 4 fields: text, flag, exp_if_correct, exp_if_incorrect
    $parts = explode( '|', $line, 4 );

    // If we don’t get exactly 4 parts, skip it
    if ( count( $parts ) !== 4 ) {
        continue;
    }

    list( $text, $flag, $exp_c, $exp_i ) = $parts;

    // Determine correctness
    $is_correct = ( $flag === '1' ) ? 1 : 0;

    // Only one explanation column will be used
    $exp_correct   = $is_correct ? sanitize_textarea_field( $exp_c ) : '';
    $exp_incorrect = ! $is_correct ? sanitize_textarea_field( $exp_i ) : '';

    // Insert into DB
    $wpdb->insert(
        'wp_ptm_answers',
        [
            'question_id'           => $question_id,
            'answer_text'           => sanitize_text_field( trim( $text ) ),
            'is_correct'            => $is_correct,
            'explanation_correct'   => $exp_correct,
            'explanation_incorrect' => $exp_incorrect,
        ]
    );
}
}


add_filter( 'cron_schedules', 'ptm_add_questions_five_min_interval' );
function ptm_add_questions_five_min_interval( $schedules ) {
    if ( ! isset( $schedules['ptm_every_5_min'] ) ) {
        $schedules['ptm_every_5_min'] = [
            'interval' => 5 * 60,
            'display'  => 'PTM Every 5 Minutes',
        ];
    }
    return $schedules;
}

// 2) Schedule _our_ event on plugin activation
register_activation_hook( __FILE__, 'ptm_schedule_add_questions_cron' );
function ptm_schedule_add_questions_cron() {
    if ( ! wp_next_scheduled( 'ptm_cron_add_questions_job' ) ) {
        wp_schedule_event(
            time(),
            'ptm_every_5_min',
            'ptm_cron_add_questions_job'
        );
    }
}

// 3) Clear _our_ event on plugin deactivation
register_deactivation_hook( __FILE__, 'ptm_clear_add_questions_cron' );
function ptm_clear_add_questions_cron() {
    $timestamp = wp_next_scheduled( 'ptm_cron_add_questions_job' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'ptm_cron_add_questions_job' );
    }
}

// 4) Hook _our_ cron event to a handler
add_action( 'ptm_cron_add_questions_job', 'ptm_do_add_questions_cron' );
function ptm_do_add_questions_cron() {
    global $wpdb;

    // pick up to 3 practice tests with fewer than 200 questions
    $tests = $wpdb->get_results( "
      SELECT wppt.id AS test_id, wppt.title
      FROM wp_ptm_practice_tests wppt
      LEFT JOIN wp_ptm_questions   wpq ON wppt.id = wpq.test_id
      GROUP BY wppt.id
      HAVING COUNT(wpq.id) < 25
      ORDER BY RAND()
      LIMIT 3
    " );
	
	
	if (empty($tests)) {
	// pick up to 3 practice tests with fewer than 200 questions
    $tests = $wpdb->get_results( "
      SELECT wppt.id AS test_id, wppt.title
      FROM wp_ptm_practice_tests wppt
      LEFT JOIN wp_ptm_questions   wpq ON wppt.id = wpq.test_id
      GROUP BY wppt.id
      HAVING COUNT(wpq.id) < 100
      ORDER BY RAND()
      LIMIT 3
    " );
	}

    foreach ( $tests as $test ) {
        ptm_generate_questions_for_test_cron( (int)$test->test_id, 10 );
    }
}

// 5) Your generator logic, refactored for cron
function ptm_generate_questions_for_test_cron( $test_id, $count ) {
    global $wpdb;

    // fetch test title
    $test = $wpdb->get_row( $wpdb->prepare(
        "SELECT title FROM wp_ptm_practice_tests WHERE id = %d",
        $test_id
    ) );
    if ( ! $test ) {
        error_log( "PTM Cron: Invalid test ID {$test_id}" );
        return;
    }

    // gather existing questions
    $existing = $wpdb->get_col( $wpdb->prepare(
        "SELECT question_text
         FROM wp_ptm_questions
         WHERE test_id = %d
         ORDER BY id ASC",
        $test_id
    ) );
    $existing_list = '';
    if ( $existing ) {
        $existing_list = "\n\nExisting questions (do not duplicate):\n- "
            . implode( "\n- ", $existing );
    }
	


    // build prompt
    $system = sprintf(
        "You are a practice-test generator. Generate %d new questions for \"%s\". Do not number them. Questions are multiple-choice or Scenario-Based Questions that will have 4 answer options added later.%s",
        $count,
        $test->title,
        $existing_list
    );
	
	$test_title = $test->title;
    
	// call OpenAI
    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if ( ! $api_key ) {
        error_log( 'PTM Cron: Missing API key' );
        return;
    }

    $body = wp_json_encode( [
        'model'       => function_exists('itu_ai_model') ? itu_ai_model('practice_test') : 'gpt-4o-mini',
        'messages'    => [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user',   'content' => 'Return a JSON array of questions.' ],
        ],
        'temperature' => 0.7,
    ] );

    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => $body,
        'timeout' => 60,
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( 'PTM Cron API error: ' . $response->get_error_message() );
        return;
    }

    $payload = json_decode( wp_remote_retrieve_body( $response ), true );
    $raw     = trim( $payload['choices'][0]['message']['content'] ?? '' );
	
    $log_file = WP_CONTENT_DIR . '/ptm_cron.log';
    $timestamp = date( '[Y-m-d H:i:s]' );
    error_log( $timestamp . ' Test ' . $test_title . ': ' . $raw . PHP_EOL, 3, $log_file );

    // strip code fences
    if ( preg_match( '/^```/', $raw ) ) {
        $raw = preg_replace( '/^```[A-Za-z]*\R/', '', $raw );
        $raw = preg_replace( '/\R```$/', '', $raw );
        $raw = trim( $raw );
    }

    // decode JSON
$questions = json_decode( $raw, true );

// check for JSON errors
if ( json_last_error() !== JSON_ERROR_NONE ) {
    error_log( 'PTM Cron JSON error: ' . json_last_error_msg() );
    return;
}

$inserted_count = 0;

foreach ( $questions as $item ) {

    // determine question text whether $item is a string or ['question' => '…']
    if ( is_string( $item ) ) {
        $text = $item;
    }
    elseif ( is_array( $item ) && isset( $item['question'] ) && is_string( $item['question'] ) ) {
        $text = $item['question'];
    }
    else {
        // unexpected format, skip
        continue;
    }

    // strip any leading numbering (e.g. "1. …")
    $text = preg_replace( '/^\d+\.\s*/', '', $text );

    // insert into questions table
    $wpdb->insert(
        'wp_ptm_questions',
        [
            'test_id'       => $test_id,
            'question_text' => sanitize_text_field( $text ),
            'created_at'    => current_time( 'mysql' ),
        ]
    );

    if ( $wpdb->insert_id ) {
        $inserted_count++;
    }
	

}
		error_log( "PTM Cron: Inserted {$inserted_count} new questions for test {$test_title}" );
}



add_action('admin_footer', function () {
   if (!isset($_GET['page']) || !in_array($_GET['page'], ['ptm_tests_edit', 'ptm_tests_add'])) return;
    ?>
<script type="text/javascript">
    document.getElementById('fetch-exam-details').addEventListener('click', function () {
        const testId = document.getElementById('test_id')?.value;
        const title = document.getElementById('title')?.value.trim();
        const testType = document.getElementById('test_type')?.value;
        const fetchStatus = document.getElementById('fetch-status');
        const descriptionField = document.getElementById('description');

        if (!title) {
            fetchStatus.textContent = 'Title is required to fetch exam details.';
            fetchStatus.style.color = 'red';
            return;
        }

        fetchStatus.textContent = 'Fetching...';
        fetchStatus.style.color = 'black';

        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'fetch_exam_details',
                test_id: testId,
                title: title,
                test_type: testType
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('Raw response:', data);

            if (!descriptionField) {
                fetchStatus.textContent = 'Description field not found.';
                fetchStatus.style.color = 'red';
                return;
            }

            let html = data?.html || data?.data?.html;

            if (!html) {
                fetchStatus.textContent = 'No HTML returned.';
                fetchStatus.style.color = 'orange';
                return;
            }

            try {
                const parsedArray = JSON.parse(html);
                const joinedHTML = parsedArray.join("\n");
                descriptionField.value = joinedHTML;

                fetchStatus.textContent = 'Exam details inserted.';
                fetchStatus.style.color = 'green';
            } catch (err) {
                fetchStatus.textContent = 'Failed to parse returned HTML.';
                fetchStatus.style.color = 'red';
                console.error('Parse error:', err);
            }
        })
        .catch(err => {
            fetchStatus.textContent = 'Error fetching exam details.';
            fetchStatus.style.color = 'red';
            console.error(err);
        });
    });
</script>

    <?php
});


<?php
add_action('wp_ajax_ptm_init_test','ptm_init_test_callback');
add_action('wp_ajax_nopriv_ptm_init_test','ptm_init_test_callback');
add_action('wp_ajax_ptm_submit_answer','ptm_submit_answer_callback');
add_action('wp_ajax_nopriv_ptm_submit_answer','ptm_submit_answer_callback');
add_action('wp_ajax_ptm_retry_test','ptm_retry_test_callback');
add_action('wp_ajax_nopriv_ptm_retry_test','ptm_retry_test_callback');


function ptm_send_question_ajax(){
    $ptm =& $_SESSION['ptm'];
    global $wpdb;

    $qid   = $ptm['question_ids'][$ptm['current_index']];
    $quest = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM wp_ptm_questions WHERE id = %d",
            $qid
        )
    );
	
	$anss = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM wp_ptm_answers
			   WHERE question_id = %d
			ORDER BY RAND()",
			$qid
		)
	);

    // Build answers array
    $labels = ['A','B','C','D','E','F'];
    $answers_data = [];
    foreach ($anss as $i => $a) {
        $answers_data[] = [
            'id'    => (int)$a->id,
            'label' => $labels[$i] ?? '',
            'text'  => $a->answer_text
        ];
    }

	
    wp_send_json([
        'complete'  => false,
        'question'  => [
            'id'      => $qid,
            'text'    => $quest->question_text,
            'answers' => $answers_data
        ],
        'current' => $ptm['current_index'] + 1,
        'total'   => count($ptm['question_ids'])
    ]);
}

/**
 * Initialize test: pick 25 random, then send first.
 */
function ptm_init_test_callback(){
    check_ajax_referer('ptm_nonce','nonce');
    if (!session_id()) {
        if (headers_sent()) { ob_start(); session_start(); ob_end_clean(); }
        else { session_start(); }
    }

    $test_id = intval($_POST['test_id']);
    global $wpdb;

    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT q.id FROM wp_ptm_questions q JOIN wp_ptm_answers a on q.id = a.question_id WHERE test_id = %d ORDER BY RAND() LIMIT 25",
            $test_id
        )
    );

    $_SESSION['ptm'] = [
        'test_id'       => $test_id,
        'question_ids'  => $ids,
        'current_index' => 0,
        'answers'       => [],
        'missed'        => []
    ];
    session_write_close(); // Release lock so other requests aren't blocked

    ptm_send_question_ajax();
}

/**
 * Handle an answer submission.
 */
function ptm_submit_answer_callback(){
    check_ajax_referer('ptm_nonce','nonce');
    if (!session_id()) {
        if (headers_sent()) { ob_start(); session_start(); ob_end_clean(); }
        else { session_start(); }
    }

    $ptm      =& $_SESSION['ptm'];
    $selected  = intval($_POST['selected']);
    $idx       = $ptm['current_index'];
    $qid       = $ptm['question_ids'][$idx];
    global $wpdb;

    $total   = count($ptm['question_ids']);
    $is_last = ($idx === $total - 1);

    // Find correct ID
    $correct = (int)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM wp_ptm_answers
             WHERE question_id = %d AND is_correct = 1",
            $qid
        )
    );

    // Get explanation
    if ($selected === $correct) {
        $exp = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT explanation_correct FROM wp_ptm_answers WHERE id = %d",
                $correct
            )
        );
    } else {
        $exp = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT explanation_incorrect FROM wp_ptm_answers WHERE id = %d",
                $selected
            )
        );
        $ptm['missed'][$qid] = $selected;
    }

    // Record answer
    $ptm['answers'][$qid] = $selected;

    // **If last question, send final JSON (with feedback)**
    if ($is_last) {
        // Calculate score
        $score = 0;
        foreach ($ptm['answers'] as $aid) {
            $score += (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT is_correct FROM wp_ptm_answers WHERE id = %d",
                    $aid
                )
            );
        }

        session_write_close();
        wp_send_json([
            'complete' => true,
            'feedback' => [
                'is_correct'  => ($selected === $correct),
                'explanation' => $exp
            ],
            'score'  => $score,
            'current'=> $total,
            'total'  => $total,
            'missed' => count($ptm['missed'])
        ]);
    }

    // **Otherwise, advance and send next question JSON (with feedback)**
    $ptm['current_index']++;
    session_write_close(); // Release lock AFTER all session writes
    $next_qid = $ptm['question_ids'][$ptm['current_index']];

    $quest = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM wp_ptm_questions WHERE id = %d", $next_qid)
    );
    $anss = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM wp_ptm_answers WHERE question_id = %d order by RAND()", $next_qid)
    );

    $labels = ['A','B','C','D','E','F'];
    $answers_data = [];
    foreach ($anss as $i => $a) {
        $answers_data[] = [
            'id'    => (int)$a->id,
            'label' => $labels[$i] ?? '',
            'text'  => $a->answer_text
        ];
    }
	


    wp_send_json([
        'complete' => false,
        'feedback' => [
            'is_correct'  => ($selected === $correct),
            'explanation' => $exp
        ],
        'question' => [
            'id'      => $next_qid,
            'text'    => $quest->question_text,
            'answers' => $answers_data
        ],
        'current' => $ptm['current_index'] + 1,
        'total'   => $total
    ]);
}

/**
 * Retry only missed questions.
 */
function ptm_retry_test_callback(){
    check_ajax_referer('ptm_nonce','nonce');
    if (!session_id()) {
        if (headers_sent()) { ob_start(); session_start(); ob_end_clean(); }
        else { session_start(); }
    }
    $ptm =& $_SESSION['ptm'];

    $_SESSION['ptm']['question_ids']  = array_keys($ptm['missed']);
    $_SESSION['ptm']['current_index'] = 0;
    $_SESSION['ptm']['answers']       = [];
    $_SESSION['ptm']['missed']        = [];
    session_write_close(); // Release lock so other requests aren't blocked

    ptm_send_question_ajax();
}
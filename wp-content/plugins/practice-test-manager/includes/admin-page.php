<?php

/**
 * Admin pages for Practice Test Manager plugin
 */
// Register menu and submenus
add_action('admin_menu', 'ptm_register_admin_pages');
function ptm_register_admin_pages() {
    add_menu_page(
        'Practice Test Manager',
        'Practice Tests',
        'manage_options',
        'ptm_tests',
        'ptm_render_admin_tests_page',
        'dashicons-welcome-learn-more',
        26
    );

    add_submenu_page(
        'ptm_tests',
        'Add New Test',
        'Add New',
        'manage_options',
        'ptm_tests_add',
        'ptm_render_admin_test_add_page'
    );
	
	  add_submenu_page(
        'ptm_tests',
        'Add Questions',
        'Add Questions',
        'manage_options',
        'ptm_tests_add_questions',
        'ptm_render_admin_questions_add_page'
    );

    add_submenu_page(
        'ptm_tests',
        'Add Answers',
        'Add Answers',
        'manage_options',
        'ptm_tests_add_answers',
        'ptm_render_admin_answers_add_page'
    );

    add_submenu_page(
        null,
        'Edit Test',
        'Edit Test',
        'manage_options',
        'ptm_tests_edit',
        'ptm_render_admin_test_edit_page'
    );


}



// Handle form actions
add_action('admin_post_ptm_add_test',        'ptm_handle_add_test');
add_action('admin_post_ptm_update_test',     'ptm_handle_update_test');
add_action('admin_post_ptm_delete_test',     'ptm_handle_delete_test');
add_action('admin_post_ptm_add_questions',   'ptm_handle_add_questions');
add_action('admin_post_ptm_delete_question', 'ptm_handle_delete_question');
add_action('admin_post_ptm_add_answers',     'ptm_handle_add_answers');
add_action('admin_post_ptm_delete_answer',   'ptm_handle_delete_answer');
add_action('admin_post_ptm_create_test_post', 'ptm_handle_create_test_post');

/**
 * Process Add New Test form submission.
 */
function ptm_handle_add_test() {
    check_admin_referer('ptm_add_test');

    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    global $wpdb;

    $wpdb->insert('wp_ptm_practice_tests', [
        'title'          => sanitize_text_field($_POST['title']),
        'description'    => $_POST['description'],
        'is_active'      => 1,
        'test_post_id'   => 0,
        'governing_body' => sanitize_text_field($_POST['governing_body']),
        'exam_code'      => sanitize_text_field($_POST['exam_code']),
        'test_type'      => sanitize_text_field($_POST['test_type'])
    ]);

    wp_redirect(admin_url('admin.php?page=ptm_tests'));
    exit;
}

/**
 * Process Edit Test form submission.
 */
function ptm_handle_update_test() {
    check_admin_referer('ptm_update_test');

    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    global $wpdb;

    $id = intval($_POST['id']);

    $wpdb->update(
        'wp_ptm_practice_tests',
        [
            'title'          => sanitize_text_field($_POST['title']),
            'description'    => $_POST['description'],
            'is_active'      => isset($_POST['is_active']) ? 1 : 0,
            'test_post_id'   => intval($_POST['test_post_id']),
            'governing_body' => sanitize_text_field($_POST['governing_body']),
            'exam_code'      => sanitize_text_field($_POST['exam_code']),
            'test_type'      => sanitize_text_field($_POST['test_type']),
        ],
        ['id' => $id]
    );

    wp_redirect(admin_url('admin.php?page=ptm_tests'));
    exit;
}
/**
 * Process Delete Test action.
 */
function ptm_handle_delete_test() {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    check_admin_referer('ptm_delete_test_' . $id);
    if (!current_user_can('manage_options')) wp_die('Access denied');
    global $wpdb;
    $wpdb->delete('wp_ptm_practice_tests', ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=ptm_tests'));
    exit;
}
/**
 * Process Add Questions (via ChatGPT API stub).
 */
function ptm_handle_add_questions() {
    check_admin_referer('ptm_add_questions');
    current_user_can('manage_options')||wp_die('Access denied');
    global $wpdb;
    $test_id    = intval($_POST['test_id']);
    $count      = intval($_POST['count']);
    $instructions = sanitize_textarea_field($_POST['prompt_instructions']);
    $test = $wpdb->get_row($wpdb->prepare("SELECT title FROM wp_ptm_practice_tests WHERE id=%d", $test_id));
    !$test&&wp_die('Invalid test ID');
    $api_key = get_option('ai_post_api_key');
    !$api_key&&wp_die('Missing API key');

    // Fetch existing questions to provide context
    $existing_questions = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT question_text FROM wp_ptm_questions WHERE test_id = %d ORDER BY id ASC",
            $test_id
        )
    );
    $existing_list = '';
    if (! empty($existing_questions)) {
        // Prefix each existing question
        $existing_list = "\nHere are the existing questions.  Do not duplicate any existing question.:\n- " . implode("\n- ", $existing_questions);
    }
	
	$system_instruction = "You are a professional practice‑test generator. Generate {$count} practice test questions for the certification titled "
    . esc_js($test->title)
    . ". Do not number the questions. Use the following additional instructions: "
    . $instructions
    . "."
    . $existing_list;
	


    $body = wp_json_encode([
        'model'    => 'gpt-4o-mini',
        'messages' => [
            [ 'role' => 'system', 'content' => $system_instruction ],
            [ 'role' => 'user',   'content' => 'Return a JSON array of questions' ]
        ],
        'temperature' => 0.7
    ]);
    $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json'
        ],
        'body'    => $body,
        'timeout' => 60
    ]);
    is_wp_error($resp)&&wp_die($resp->get_error_message());
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $raw  = trim($data['choices'][0]['message']['content']);
    if (preg_match('/^```/', $raw)) {
        $raw = preg_replace('/^```[A-Za-z]*\R/', '', $raw);
        $raw = preg_replace('/\R```$/', '', $raw);
        $raw = trim($raw);
    }
    $questions = json_decode($raw, true);
	
	
	$inserted_count = 0;
	
    !is_array($questions)&&wp_die('Invalid JSON');
    foreach ($questions as $q) {
		if ( isset($q['question']) ) {
			$inserted_count++;
			$q = preg_replace('/^\d+\.\s*/', '', $q['question']);
			$wpdb->insert('wp_ptm_questions', [
				'test_id'       => $test_id,
				'question_text' => sanitize_text_field($q),
				'created_at'    => current_time('mysql')
			]);
    	}
	}
    wp_redirect(admin_url('admin.php?page=ptm_tests_add_questions&test_id=' . $test_id . '&added=' . $inserted_count));
    exit;
}


/**
 * Process Delete Question action (and its answers due to FK).
 */
function ptm_handle_delete_question() {
    $question_id = isset($_GET['question_id']) ? intval($_GET['question_id']) : 0;
    check_admin_referer('ptm_delete_question_' . $question_id);
    if (!current_user_can('manage_options')) wp_die('Access denied');
    global $wpdb;
    // delete answers first
    $wpdb->delete('wp_ptm_answers', ['question_id' => $question_id]);
    // delete question
    $wpdb->delete('wp_ptm_questions', ['id' => $question_id]);
    $test_id = intval($_GET['test_id']);
    wp_redirect(admin_url('admin.php?page=ptm_tests_add_questions&test_id=' . $test_id));
    exit;
}

function ptm_handle_add_answers(){
    check_admin_referer('ptm_add_answers');
    current_user_can('manage_options')||wp_die('Access denied');
    global $wpdb;
    $question_id = intval($_POST['question_id']);
    $test_id     = intval($_POST['test_id']);
    $question    = $wpdb->get_row($wpdb->prepare("SELECT question_text FROM wp_ptm_questions WHERE id=%d",$question_id));
    !$question&&wp_die('Invalid question');
    $api_key = get_option('ai_post_api_key');
    !$api_key&&wp_die('Missing API key');

	$system = <<<EOT
	You are a practice‑test generator.  For the question below, return exactly four newline‑separated answer lines, no extra text, in this format:

	<answer_text>|<is_correct_flag>|<explanation_if_correct>|<explanation_if_incorrect>\n

	Rules:
	– You must output exactly 4 lines.
	– Exactly one line uses “1” for <is_correct_flag>, the other three use “0”.
	– For the correct answer line (flag=1), put the justification in <explanation_if_correct> and leave <explanation_if_incorrect> empty.
	– For each incorrect line (flag=0), put the distractor reasoning in <explanation_if_incorrect> and leave <explanation_if_correct> empty.
	- Place each answer on a new line. return a valid JSON response

	Question: '{$question->question_text}'
	EOT;
	
$body = wp_json_encode([
    'model'       => 'gpt-4o-mini',
    'messages'    => [
        [
            'role'    => 'system',
            'content' => $system,
        ],
    ],
    'temperature' => 0.7,
]);
    $resp=wp_remote_post('https://api.openai.com/v1/chat/completions',[ 'headers'=>['Authorization'=>'Bearer '.$api_key,'Content-Type'=>'application/json'], 'body'=>$body,'timeout'=>60 ]);
    is_wp_error($resp)&&wp_die($resp->get_error_message());
    $data=json_decode(wp_remote_retrieve_body($resp),true);
    $raw=trim($data['choices'][0]['message']['content']);
	
	if (preg_match('/^```/', $raw)) {
        $raw = preg_replace('/^```[A-Za-z]*\R/', '', $raw);
        $raw = preg_replace('/\R```$/', '', $raw);
        $raw = trim($raw);
    }
//	print_r($raw);
//	die;
	
// decode the JSON into an associative array
$data = json_decode($raw, true);

// make sure we have exactly what we expect
if ( ! isset($data['answers']) || ! is_array($data['answers']) ) {
	print_r($raw);
	die;
  //  wp_die('Unexpected API response format');
}

$inserted = 0;

foreach ( $data['answers'] as $ans ) {
    // split into [text, flag, explanation]
    $parts = explode('|', $ans, 3);
    if ( count($parts) !== 3 ) {
        continue;
    }
    list($text, $flag, $explanation) = $parts;

    // determine correct vs incorrect
    $is_correct = ($flag === '1') ? 1 : 0;
    $exp_correct   = $is_correct ? sanitize_textarea_field($explanation) : '';
    $exp_incorrect = !$is_correct ? sanitize_textarea_field($explanation) : '';

    // insert into the database
    $wpdb->insert(
        'wp_ptm_answers',
        [
            'question_id'            => $question_id,
            'answer_text'            => sanitize_text_field(trim($text)),
            'is_correct'             => $is_correct,
            'explanation_correct'    => $exp_correct,
            'explanation_incorrect'  => $exp_incorrect,
        ]
    );

    if ( $wpdb->insert_id ) {
        $inserted++;
    }
}
 //   wp_redirect(admin_url('admin.php?page=ptm_tests_add_answers&test_id='.$test_id.'&question_id='.$question_id.'&added='.$inserted)); exit;
      wp_redirect(admin_url('admin.php?page=ptm_tests_add_questions&test_id=' . $test_id));

}

// Delete Answer
function ptm_handle_delete_answer(){
    $aid = intval($_GET['answer_id']);
    check_admin_referer('ptm_delete_answer_'.$aid);
    current_user_can('manage_options')||wp_die('Access denied');
    global $wpdb;
    $wpdb->delete('wp_ptm_answers',['id'=>$aid]);
    wp_redirect(admin_url('admin.php?page=ptm_tests_add_answers&test_id='.intval($_GET['test_id']).'&question_id='.intval($_GET['question_id'])));
    exit;
}


/**
 * Renders the list of all Practice Tests.
 */
function ptm_render_admin_tests_page() {
    global $wpdb;
    $tests = $wpdb->get_results(
        "SELECT id, title, description, exam_code, governing_body, is_active, created_at, test_post_id FROM wp_ptm_practice_tests ORDER BY id ASC"
    );
    ?>
    <div class="wrap">
      <h1>Practice Tests <a href="<?php echo esc_url(admin_url('admin.php?page=ptm_tests_add')); ?>" class="page-title-action">Add New</a></h1>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th width="5%">ID</th>
            <th>Title</th>
            <th width="5%">Active?</th>
            <th width="10%">Created At</th>
            <th width="5%">Questions</th>
            <th width="15%">Shortcode</th>
            <th width="20%">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($tests): foreach ($tests as $test): ?>
          <?php
            // Count number of questions for this test
            $question_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM wp_ptm_questions WHERE test_id = %d",
                    $test->id
                )
            );
	        $answer_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM wp_ptm_answers a join wp_ptm_questions q on a.question_id = q.id WHERE q.test_id = %d",
                    $test->id
                )
            );
            $edit_url   = admin_url('admin.php?page=ptm_tests_edit&id=' . $test->id);
            $delete_url = wp_nonce_url(
                admin_url('admin-post.php?action=ptm_delete_test&id=' . $test->id),
                'ptm_delete_test_' . $test->id
            );
            $add_q_url  = admin_url('admin.php?page=ptm_tests_add_questions&test_id=' . $test->id);
			$edit_post_url = admin_url('post.php?post=' . $test->test_post_id . '&action=edit');
		    $create_url = admin_url('admin-post.php?action=ptm_create_test_post&test_id=' . $test->id);

          ?>
          <tr>
            <td><?php echo esc_html($test->id); ?></td>
            <td><?php echo esc_html($test->title); ?></td>
            <td><?php echo $test->is_active ? 'Yes' : 'No'; ?></td>
            <td><?php echo esc_html($test->created_at); ?></td>
            <td><?php echo $question_count; ?></td>
            <td>
              <input type="text" readonly  value="[practice_test test_id=<?php echo esc_attr($test->id); ?>]" onclick="this.select();" />
            </td>
            <td>
			<?php if ($question_count == 0): ?>
              <a href="<?php echo esc_url($add_q_url); ?>">Add Questions</a> |
			<?php endif?>
			<?php if ($question_count > 0): ?>
              <a href="<?php echo esc_url($add_q_url); ?>">View Questions</a> |
			<?php endif?>
              <a href="<?php echo esc_url($edit_url); ?>">Edit Test</a> |
              <a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Delete this test?');">Delete</a> | 
			<?php if ($test->test_post_id > 0): ?>
				<a href="<?php echo esc_url($edit_post_url); ?>">Edit Post</a> 
			<?php endif?>
			<?php 
if (
    intval($test->test_post_id) === 0 &&
    !empty($test->exam_code) &&
    !empty($test->description) &&
    !empty($test->governing_body && $question_count >= 25 && $answer_count >= 40)
) {
    echo '<a href="' . esc_url($create_url) . '" target="_blank" rel="noopener" class="button">Create New Post</a>';
}
			?>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7">No practice tests found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/**
 * Renders the Add New Practice Test form.
 */
function ptm_render_admin_test_add_page() {
    ?>
    <div class="wrap">
      <h1>Add New Practice Test</h1>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('ptm_add_test'); ?>
        <input type="hidden" name="action" value="ptm_add_test">
        <table class="form-table">
          <tr>
            <th><label for="title">Title</label></th>
            <td><input name="title" id="title" type="text" class="regular-text" required></td>
          </tr>
          <tr>
            <th><label for="description">Description</label></th>
            <td><textarea name="description" id="description" class="large-text" rows="10"></textarea>
			  
			    					<button type="button" class="button button-secondary" id="fetch-exam-details">Fetch Exam Details (ChatGPT)</button>
					<span id="fetch-status" style="margin-left: 10px;"></span>
			  </td>
          </tr>
		  <tr>
				<th scope="row"><label for="test_post_id">Post ID (Displays This Test)</label></th>
				<td><input name="test_post_id" type="number" id="test_post_id" value="" class="regular-text" /></td>
		  </tr>		
		  <tr>
				<th scope="row"><label for="test_body">Governing Body</label></th>
				<td><input name="governing_body" type="text" id="governing_body" value="" class="regular-text" /></td>
		  </tr>			
		  <tr>
				<th scope="row"><label for="exam_code">Exam Code</label></th>
				<td><input name="exam_code" type="text" id="exam_code" value="" class="regular-text" /></td>
		  </tr>	
		<tr>
		  <th scope="row"><label for="test_type">Test Type</label></th>
		  <td>
			<select name="test_type" id="test_type" class="regular-text">
			  <option value="certification" selected>Certification</option>
			  <option value="skills">Skills</option>
			</select>
		  </td>
		</tr>
        </table>
        <?php submit_button('Add Test'); ?>
      </form>
    </div>
    <?php
}

/**
 * Renders the Edit Practice Test form.
 */
function ptm_render_admin_test_edit_page() {
    global $wpdb;
    $id   = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $test = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_ptm_practice_tests WHERE id = %d", $id));
    if (! $test) {
        echo '<div class="notice notice-error"><p>Test not found.</p></div>';
        return;
    }
    ?>
    <div class="wrap">
      <h1>Edit Practice Test</h1>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('ptm_update_test'); ?>
        <input type="hidden" name="action" value="ptm_update_test">
        <input type="hidden" name="id" value="<?php echo esc_attr($test->id); ?>">
        <table class="form-table">
          <tr>
            <th><label for="title">Title</label></th>
            <td><input name="title" id="title" type="text" class="regular-text" value="<?php echo esc_attr($test->title); ?>" required></td>
          </tr>
          <tr>
            <th><label for="description">Description</label></th>
            <td><textarea name="description" id="description" class="large-text" rows="10"><?php echo esc_textarea($test->description); ?></textarea>
			  					<button type="button" class="button button-secondary" id="fetch-exam-details">Fetch Exam Details (ChatGPT)</button>
					<span id="fetch-status" style="margin-left: 10px;"></span>
			  </td>
          </tr>
          <tr>
            <th><label for="is_active">Active?</label></th>
            <td><input name="is_active" id="is_active" type="checkbox" value="1" <?php checked($test->is_active, 1); ?>></td>
          </tr>
		  <tr>
				<th scope="row"><label for="test_post_id">Post ID (Displays This Test)</label></th>
				<td><input name="test_post_id" type="number" id="test_post_id" value="<?php echo esc_attr($test->test_post_id ?? ''); ?>" class="regular-text" /></td>
		  </tr>	
		  <tr>
				<th scope="row"><label for="test_body">Governing Body</label></th>
				<td><input name="governing_body" type="text" id="governing_body" class="regular-text"  value="<?php echo esc_attr($test->governing_body ?? ''); ?>"/></td>
		  </tr>			
		  <tr>
				<th scope="row"><label for="exam_code">Exam Code</label></th>
				<td><input name="exam_code" type="text" id="exam_code" class="regular-text"  value="<?php echo esc_attr($test->exam_code ?? ''); ?>"/></td>
		  </tr>
		<tr>
		  <th scope="row"><label for="test_type">Test Type</label></th>
		  <td>
			<select name="test_type" id="test_type" class="regular-text">
			  <option value="certification" <?php selected($test->test_type, 'certification'); ?>>Certification</option>
			  <option value="skills" <?php selected($test->test_type, 'skills'); ?>>Skills</option>
			</select>
		  </td>
		</tr>
        </table>
        <?php submit_button('Update Test'); ?>
      </form>
    </div>
    <?php
}

/**
 * Renders the Add Questions page and existing questions.
 */
function ptm_render_admin_questions_add_page() {
    global $wpdb;
    $test_id = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;
    $test = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, title FROM wp_ptm_practice_tests WHERE id = %d",
            $test_id
        )
    );
    if (! $test) {
        echo '<div class="notice notice-error"><p>Test not found.</p></div>';
        return;
    }
    ?>
    <div class="wrap">
      <h1>Add Questions to: <?php echo esc_html($test->title); ?>
        <a href="<?php echo esc_url(add_query_arg('page','ptm_tests', admin_url('admin.php'))); ?>" class="page-title-action">Back to Tests</a>
      </h1>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('ptm_add_questions'); ?>
        <input type="hidden" name="action" value="ptm_add_questions">
        <input type="hidden" name="test_id" value="<?php echo esc_attr($test_id); ?>">
        <table class="form-table">
          <tr>
            <th><label for="count">Number of Questions</label></th>
            <td><input name="count" id="count" type="number" class="small-text" value="10" min="1" max="50" required></td>
          </tr>
          <tr>
            <th><label for="prompt_instructions">Prompt Instructions</label></th>
            <td><textarea name="prompt_instructions" id="prompt_instructions" class="large-text" rows="3">Ensure you gather questions that focus on the objectives of this exam.</textarea></td>
          </tr>
        </table>
        <?php submit_button('Generate Questions'); ?>
      </form>
		


      <?php if (isset($_GET['added'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['added']); ?> questions added.</p></div>
      <?php endif; ?>

      <h2>Existing Questions</h2>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Question Text</th>
            <th>Answer Count</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $questions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, question_text FROM wp_ptm_questions WHERE test_id = %d ORDER BY id DESC",
                $test_id
            )
        );
        if ($questions):
            foreach ($questions as $q):
                $ans_count = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM wp_ptm_answers WHERE question_id = %d",
                        $q->id
                    )
                );
                $add_answers_url = add_query_arg(
                    [
                        'page'        => 'ptm_tests_add_answers',
                        'test_id'     => $test_id,
                        'question_id' => $q->id
                    ],
                    admin_url('admin.php')
                );
                ?>
                <tr>
                  <td><?php echo esc_html($q->id); ?></td>
                  <td><?php echo esc_html($q->question_text); ?></td>
                  <td><?php echo $ans_count; ?></td>
                  <td>
                    <a href="<?php echo esc_url($add_answers_url); ?>">Add Answers</a>
                    <?php if ($ans_count > 0): ?>
                      | <span style="color:#999;" title="Delete answers first to delete question">Delete Question</span>
                    <?php else: ?>
                      | <?php
                          $del_q_url = wp_nonce_url(
                              add_query_arg(
                                  [
                                      'action'      => 'ptm_delete_question',
                                      'test_id'     => $test_id,
                                      'question_id' => $q->id
                                  ],
                                  admin_url('admin-post.php')
                              ),
                              'ptm_delete_question_' . $q->id
                          );
                      ?>
                      <a href="<?php echo esc_url($del_q_url); ?>" onclick="return confirm('Delete this question?');">Delete Question</a>
                    <?php endif; ?>
                  </td>
                </tr>
            <?php endforeach;
        else: ?>
          <tr><td colspan="4">No questions found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}


// Render Answer Page
function ptm_render_admin_answers_add_page(){
    global $wpdb;
    $test_id = intval($_GET['test_id']);
    $question_id = intval($_GET['question_id']);
    $q = $wpdb->get_row($wpdb->prepare("SELECT question_text FROM wp_ptm_questions WHERE id=%d",$question_id));
    if(!$q):?><div class="notice notice-error"><p>Question not found.</p></div><?php return; endif;
    ?>
    <div class="wrap">
      <h1>Add Answers for: <?php echo esc_html($q->question_text);?> <a href="<?php echo esc_url(admin_url('admin.php?page=ptm_tests_add_questions&test_id='.$test_id));?>" class="page-title-action">Back</a></h1>
      <form id="ptm-add-answers-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
        <?php wp_nonce_field('ptm_add_answers');?>
        <input type="hidden" name="action" value="ptm_add_answers">
        <input type="hidden" name="test_id" value="<?php echo $test_id;?>">
        <input type="hidden" name="question_id" value="<?php echo $question_id;?>">
        <?php submit_button('Generate Answers');?>
      </form>
		
			<script>
  document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('ptm-add-answers-form');
    if (form) {
      form.submit();
    }
  });
</script>
      <?php if(isset($_GET['added'])): ?><div class="notice notice-success"><p><?php echo intval($_GET['added']);?> answers added.</p></div><?php endif;?>
      <h2>Existing Answers</h2>
      <table class="wp-list-table widefat fixed striped">
        <thead><tr><th>ID</th><th>Answer</th><th>Correct?</th><th>Explanation Correct</th><th>Explanation Incorrect</th><th>Actions</th></tr></thead>
        <tbody><?php
        $answers=$wpdb->get_results($wpdb->prepare("SELECT * FROM wp_ptm_answers WHERE question_id=%d ORDER BY id ASC",$question_id));
        if($answers): foreach($answers as $ans):
            $del_url=wp_nonce_url(admin_url('admin-post.php?action=ptm_delete_answer&answer_id='.$ans->id.'&test_id='.$test_id.'&question_id='.$question_id),'ptm_delete_answer_'.$ans->id);
            ?>
            <tr>
              <td><?php echo $ans->id;?></td>
              <td><?php echo esc_html($ans->answer_text);?></td>
              <td><?php echo $ans->is_correct?'Yes':'No';?></td>
              <td><?php echo esc_html($ans->explanation_correct);?></td>
              <td><?php echo esc_html($ans->explanation_incorrect);?></td>
              <td><a href="<?php echo esc_url($del_url);?>" onclick="return confirm('Delete this answer?');">Delete</a></td>
            </tr>
        <?php endforeach; else: ?><tr><td colspan="6">No answers found.</td></tr><?php endif;?></tbody>
      </table>
    </div>
    <?php
}


add_action('wp_ajax_fetch_exam_details', 'ptm_fetch_exam_details');

function ptm_fetch_exam_details() {
    $api_key = get_option('ai_post_api_key');
    if (!$api_key) {
        error_log('PTM Ajax: Missing API key');
        wp_send_json_error(['message' => 'Missing API key.']);
    }

    $title = sanitize_text_field($_POST['title'] ?? '');
    $test_type = sanitize_text_field($_POST['test_type'] ?? 'certification');

    if (empty($title)) {
        wp_send_json_error(['message' => 'No title provided.']);
    }


	
if ($test_type === 'certification') {

    $example_html = <<<EOT
	<!-- wp:heading {"level":3} -->
	<h3 class="wp-block-heading">Exam information</h3>
	<!-- /wp:heading -->

	<!-- wp:list -->
	<ul class="wp-block-list">
	<li>Exam title: Microsoft Certified: Azure Security Engineer Associate</li>
	<li>Exam code: AZ-500</li>
	<li>Price: USD 165 (may vary by region)</li>
	<li>Delivery methods:
	<ul class="wp-block-list">
	<li>In-person at Pearson VUE testing centers</li>
	<li>Online with remote proctoring via Pearson VUE</li>
	</ul></li>
	</ul>
	<!-- /wp:list -->

	<!-- wp:heading {"level":3} -->
	<h3 class="wp-block-heading">Exam structure</h3>
	<!-- /wp:heading -->

	<!-- wp:list -->
	<ul class="wp-block-list">
	<li>Number of questions: 40–60</li>
	<li>Question types: multiple-choice, multiple-response, drag-and-drop, and case studies</li>
	<li>Duration: 120 minutes</li>
	<li>Passing score: 700 out of 1,000</li>
	</ul>
	<!-- /wp:list -->

	<!-- wp:heading {"level":3} -->
	<h3 class="wp-block-heading">Domains covered</h3>
	<!-- /wp:heading -->

	<ol class="wp-block-list">
	<li>Manage identity and access (30 – 35 %)</li>
	<li>Implement platform protection (20 – 25 %)</li>
	<li>Manage security operations (15 – 20 %)</li>
	<li>Secure data and applications (25 – 30 %)</li>
	</ol>

	<!-- wp:heading {"level":3} -->
	<h3 class="wp-block-heading">Recommended experience</h3>
	<!-- /wp:heading -->

	<ul class="wp-block-list">
	<li>Two to three years of hands-on experience securing cloud workloads and hybrid environments</li>
	<li>Familiarity with scripting and automation using PowerShell, Azure CLI, or ARM templates</li>
	<li>Knowledge of core Azure services and security technologies such as Azure Active Directory, Security Center, Key Vault, and Sentinel</li>
	</ul>

EOT;
	
}
	
if ($test_type === 'skills') {
	
    $example_html = <<<EOT
<!-- wp:spacer -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Test Information</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Test Title: Basic Numerical Reasoning for Tech Careers<br>Delivery Method: Online via Vision Training Systems</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Topics Covered</h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Problem-Solving Techniques</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Logical Reasoning</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Effective Communication Skills</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Workplace Readiness</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Data Interpretation</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Recommended Audience</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>This test is ideal for IT professionals looking to enhance their numerical reasoning skills and improve their problem-solving capabilities in a technical environment.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Recommended Preparation</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Familiarity with basic mathematical concepts and a background in IT or technical roles will be beneficial for this test.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p></p>
<!-- /wp:paragraph -->

EOT;

}	

    // Conditional prompt
    if ($test_type === 'skills') {
        $prompt = <<<EOT
You are helping build content for an IT training platform.

Given the title and a short description of a skills-based, non-certification test (like logical reasoning, communication skills, or problem solving for IT professionals), return a structured HTML block that mimics the layout used for certification exam pages.

Structure your output using valid HTML and include the following sections wrapped in <section> tags with appropriate <h3> headings and <ul>/<p> elements:

1. Test Information
   - Test title
   - Delivery method (Online via Vision Training Systems)

2. Topics Covered
   - List 4–6 relevant topics or skills

3. Recommended Audience
   - Who should take this test?

4. Recommended Preparation
   - Any helpful background knowledge or experience

Output clean HTML only. Do not explain, describe, or format as Markdown. Do not include backticks or code fences. This is for a WordPress block editor.

Course content:
Title: {$title}
Description: This is a skills-based test designed to help IT professionals strengthen their abilities in core areas such as problem-solving, communication, logic, and workplace readiness.
EOT;
    } else {
        $prompt = "Using the certification titled \"$title\", return exam details formatted in valid HTML structured exactly like this example:\n\n{$example_html}\n\nDo not explain or introduce the response. Do Not Guess Exam Codes or Prices.";
    }

    // Make API request
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => 'Return a JSON array of the html created']
            ],
            'temperature' => 0.3
        ]),
        'timeout' => 60
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'API request failed.']);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $content = $body['choices'][0]['message']['content'] ?? '';

    // Clean up code block fences if returned
    if (preg_match('/^```/', $content)) {
        $content = preg_replace('/^```[A-Za-z]*\R/', '', $content);
        $content = preg_replace('/\R```$/', '', $content);
        $content = trim($content);
    }

    if (empty($content)) {
        wp_send_json_error(['message' => $body]);
    }

    wp_send_json_success(['html' => $content]);
}


function ptm_handle_create_test_post() {
    if (!current_user_can('edit_posts')) {
        wp_die('You are not allowed to create posts.');
    }

    if (!isset($_GET['test_id'])) {
        wp_die('Missing test ID.');
    }

    global $wpdb;

    $test_id = intval($_GET['test_id']);
    $table   = 'wp_ptm_practice_tests';

    $test = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $test_id));

    if (!$test) {
        wp_die('Test not found.');
    }

    // Create post content
    $post_content = "[practice_test test_id={$test->id}]\n\n" . $test->description;

    // Get or create the category
    $category_name = 'Practice Tests';
    $category = get_term_by('name', $category_name, 'category');
    $cat_id = $category ? $category->term_id : wp_create_category($category_name);

    $post_id = wp_insert_post([
        'post_title'    => $test->title . ' Practice Test',
        'post_content'  => $post_content,
        'post_status'   => 'draft',
        'post_type'     => 'post',
        'post_category' => [$cat_id],
        'post_excerpt'  => 'Practice with the ' . $test->title . ', exam overview, domain breakdown.'
    ]);

    if (is_wp_error($post_id)) {
        wp_die('Failed to create post.');
    }

    // Set default featured image
    $default_image_id = 1112307;
    set_post_thumbnail($post_id, $default_image_id);

    // Update ACF fields
    update_field('field_68894808bbb08', $test->governing_body, $post_id);
    update_field('field_68894860bbb09', $test->exam_code, $post_id);
	
    // Update original test row with new post ID
    $wpdb->update($table, ['test_post_id' => $post_id], ['id' => $test_id]);

    // Redirect to edit screen
    wp_redirect(admin_url('post.php?post=' . $post_id . '&action=edit'));
    exit;
}

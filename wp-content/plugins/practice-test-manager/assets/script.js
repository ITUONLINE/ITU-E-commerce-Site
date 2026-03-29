jQuery(function($){
  var container      = $('#ptm-test');
  if (!container.length) return;

  // Inline SVGs for spinner, correct, and incorrect
  var spinnerSvg = '<svg width="24" height="24" viewBox="0 0 50 50" fill="none" xmlns="http://www.w3.org/2000/svg">'
                 + '<circle cx="25" cy="25" r="20" stroke="#C026D3" stroke-width="5" stroke-linecap="round"'
                 + ' stroke-dasharray="31.415,31.415" transform="rotate(0 25 25)">'
                 + '<animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="1s" repeatCount="indefinite"/>'
                 + '</circle></svg>';

  var correctSvg = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
                 + '<circle cx="12" cy="12" r="10" fill="#4CAF50"/>'
                 + '<path d="M8 12l2 2l4-4" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                 + '</svg>';

  var incorrectSvg = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
                   + '<circle cx="12" cy="12" r="10" fill="#dc2626"/>'
                   + '<path d="M15 9l-6 6M9 9l6 6" stroke="#ffffff" stroke-width="2" stroke-linecap="round"/>'
                   + '</svg>';

  var clientCurrent  = 0;
  var totalQuestions = 0;

  // 1) Kick off / restart the test
  function initTest(){
    $.post(ptm_ajax.ajax_url, {
      action:  'ptm_init_test',
      nonce:   ptm_ajax.nonce,
      test_id: container.data('test-id')
    }, function(res){
      totalQuestions = res.total;
      clientCurrent  = 1;
      renderQuestion(res);
    }, 'json');
  }

  // 2) Render the current question
  function renderQuestion(res){
    var html  = '<div class="ptm-container">';
        html += '<div class="ptm-question-count">Question ' + clientCurrent + ' of ' + totalQuestions + '</div>';
        html += '<div class="ptm-instruction">Click the correct answer below</div>';
        html += '<h2 class="ptm-question">' + res.question.text + '</h2>';
        html += '<div class="ptm-answers">';
    res.question.answers.forEach(function(a){
      html += '<div class="ptm-answer" data-id="' + a.id + '">';
      html +=   '<div class="ptm-answer-label">' + a.label + '</div>';
      html +=   '<div class="ptm-answer-text">'  + a.text  + '</div>';
      html += '</div>';
    });
        html += '</div></div>';

    container.html(html);
    bindAnswerClicks();
  }

  // 3) After clicking an answer: spinner → feedback → Next/Submit
  function bindAnswerClicks(){
    container.find('.ptm-answer').on('click', function(){
      var ans = $(this),
          id  = ans.data('id');

      // Disable all other answers
      container.find('.ptm-answer')
        .off('click')
        .addClass('ptm-disabled');
      ans.removeClass('ptm-disabled');

      // Show spinner
      ans.find('.ptm-answer-label').html(spinnerSvg);

      // AJAX submit
      $.post(ptm_ajax.ajax_url, {
        action:   'ptm_submit_answer',
        nonce:    ptm_ajax.nonce,
        selected: id
      }, function(resp){
        // Swap spinner for success/fail icon
        if (resp.feedback.is_correct) {
          ans.find('.ptm-answer-label').html(correctSvg);
          ans.addClass('ptm-correct');
        } else {
          ans.find('.ptm-answer-label').html(incorrectSvg);
          ans.addClass('ptm-incorrect');
        }

        // Show explanation
        ans.find('.ptm-answer-text').text(resp.feedback.explanation);

        // Append the appropriate button
        if (clientCurrent < totalQuestions) {
          container.append(
            '<button id="ptm-next" class="ptm-next-btn">Next Question</button>'
          );
          $('#ptm-next').on('click', function(){
            clientCurrent++;
            renderQuestion(resp);
          });
        } else {
          container.append(
            '<button id="ptm-submit" class="ptm-next-btn">Submit Test</button>'
          );
          $('#ptm-submit').on('click', function(){
            renderCompletion(resp);
          });
        }
      }, 'json');
    });
  }

  // 4) Final results screen
  function renderCompletion(res){
    var pct      = (res.score / res.total) * 100,
        pctFixed = pct.toFixed(2),
        title;

    if (pct < 50) {
      title = 'Hang in there! You scored ' + pctFixed + '%. A bit more practice will help.';
    }
    else if (pct <= 70) {
      title = 'Good effort! You scored ' + pctFixed + '%. Keep reviewing and you’ll improve.';
    }
    else if (pct < 90) {
      title = 'Great job! You scored ' + pctFixed + '%. You’re almost at mastery.';
    }
    else {
      title = 'Excellent! You scored ' + pctFixed + '%. You’re ready for the real exam!';
    }

    var html  = '<div class="ptm-container">';
        html += '<h2>' + title + '</h2>';
        html += '<p>Answered ' + res.score  + ' of ' + res.total  + ' correctly.</p>';
        html += '<p>Missed ' + res.missed + ' questions.</p>';
        html += '<button id="ptm-new" class="ptm-next-btn">Begin a New Test</button>';
        if (res.missed > 0) {
          html += ' <button id="ptm-retry" class="ptm-next-btn">Re-Try Missed Questions</button>';
        }
        html += '</div>';

    container.html(html);
    $('#ptm-new').on('click', initTest);
    if (res.missed > 0) {
      $('#ptm-retry').on('click', function(){
        $.post(ptm_ajax.ajax_url, {
          action: 'ptm_retry_test',
          nonce:  ptm_ajax.nonce
        }, function(resp){
          totalQuestions = resp.total;
          clientCurrent  = 1;
          renderQuestion(resp);
        }, 'json');
      });
    }
  }

  // Launch
  initTest();
});

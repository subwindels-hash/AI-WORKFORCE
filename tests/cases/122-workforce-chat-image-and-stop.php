<?php
/**
 * Workforce Chat System — Image file upload and Stop button controls.
 */

test('workforce file analyzer supports image uploads (JPG, PNG, WEBP, GIF, SVG)', function () {
    $analyzer = new \AIWorkforce\WorkforceFileAnalyzer();

    // Create a temporary SVG image file
    $svgPath = tempnam(sys_get_temp_dir(), 'wf_test_svg_');
    file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100"><title>Quarterly Growth Chart</title><text x="20" y="50">Revenue +45%</text></svg>');

    $analysis = $analyzer->analyze([
        'name' => 'chart.svg',
        'tmp_name' => $svgPath,
        'size' => filesize($svgPath),
        'error' => UPLOAD_ERR_OK,
    ]);
    @unlink($svgPath);

    assert_equals('image', $analysis['attachment']['kind'], 'attachment kind must be image');
    assert_equals('svg', $analysis['attachment']['extension'], 'attachment extension');
    assert_contains('Revenue +45%', $analysis['contextMessage'], 'SVG text content extracted');
    assert_contains('Quarterly Growth Chart', $analysis['contextMessage'], 'SVG title extracted');
    assert_true(!empty($analysis['facts']['extractedContent']['text']), 'extractedContent contains text');
});

test('workforce view contains image upload extensions in file input and hint', function () {
    $view = file_get_contents(FCPATH . 'application/views/workforce/index.php');
    assert_contains('.jpg', $view);
    assert_contains('.png', $view);
    assert_contains('.webp', $view);
    assert_contains('.svg', $view);
    assert_contains('image/*', $view);
    assert_contains('JPG, PNG, WEBP, GIF, BMP, SVG, TIFF', $view, 'hint contains image formats');
});

test('workforce chat and speech provider contain working stop controls', function () {
    $view = file_get_contents(FCPATH . 'application/views/workforce/index.php');
    assert_contains('id="wf-chat-stop"', $view, 'generation stop button exists');
    assert_contains('id="wf-chat-mic-stop"', $view, 'mic stop button exists');
    assert_contains('stopCurrentAction', $view, 'stopCurrentAction function exists');
    assert_contains('activeAbortControllers', $view, 'abort controller tracking exists');

    $speech = file_get_contents(FCPATH . 'assets/js/speech-provider.js');
    assert_contains('stopListening()', $speech);
    assert_contains('this._rec.abort()', $speech);
    assert_contains('stopBtn.style.display', $speech);

    $chat = file_get_contents(FCPATH . 'assets/js/ai_workforce-chat.js');
    assert_contains('is-playing', $chat);
    assert_contains('speechSynthesis.cancel()', $chat);
});

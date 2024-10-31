jQuery(document).ready(function($) {

    function saveSSESupportStatus(supported) {
        $.ajax({
            url: sseTestParams.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sse_save_test_result', // This should match the action in WordPress
                security: sseTestParams.nonce,
                sseSupported: supported
            },
            success: function(response) {
                console.log('SSE support status saved: ' + response.data);
            },
            error: function(xhr, status, error) {
                console.error('Error saving SSE support status: ' + error);
            }
        });
    }
    function testSSESupport(e) {
        e.preventDefault();
        var messageCount = 0;
        var firstMessageTime = 0;
        const checkingText = 'checking...';
        $('#ct-sse-test-result').html(checkingText).css('color', 'blue');

        if (typeof(EventSource) !== "undefined") {
            var source = new EventSource(sseTestParams.ajaxUrlTest);

            source.addEventListener('test', function(event) {
                var currentTime = new Date().getTime();
                var data = JSON.parse(event.data);

                if (data.message === 'SSE test message') {
                    messageCount++;

                    if (messageCount === 1) {
                        firstMessageTime = currentTime;
                    }

                    // Check if messages are being streamed (not all received at once)
                    if (messageCount >= 3 && (currentTime - firstMessageTime) > 1000) {
                        $('#ct-sse-test-result').html('SSE is supported!').css('color', 'green');
                        saveSSESupportStatus(true);
                        source.close();
                    }
                }
            }, false);

            source.addEventListener('error', function(event) {
                if (event.eventPhase === EventSource.CLOSED) {
                    $('#ct-sse-test-result').html('SSE not supported or test failed.').css('color', 'red');
                    saveSSESupportStatus(false);
                }
                source.close();
            }, false);
        } else {
            $('#ct-sse-test-result').html('SSE not supported by your browser.').css('color', 'red');
        }
    }

    $('#test-sse-button').on('click', testSSESupport);
});

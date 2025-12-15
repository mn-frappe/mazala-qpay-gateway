(function($){
    $(function(){
        $('#wooqpay_retry_selected').on('click', function(e){
            e.preventDefault();
            var ids = [];
            $('input.wooqpay-refund-select:checked').each(function(){ ids.push($(this).val()); });
            if (!ids.length){ alert('Select at least one refund row'); return; }
            if (!confirm('Retry ' + ids.length + ' refund(s)?')) return;
            var $btn = $(this).prop('disabled', true).text('Retrying...');
            $.post(wooqpay_admin.ajax_url, { action: 'wooqpay_retry_refunds', ids: ids, _ajax_nonce: wooqpay_admin.nonce }, function(resp){
                $btn.prop('disabled', false).text('Retry Selected (AJAX)');
                if (!resp || !resp.success){ alert('Retry failed: ' + (resp && resp.data ? resp.data : 'unknown')); return; }
                var results = resp.data;
                var ok = 0, fail = 0;
                $.each(results, function(id, r){
                    if (r.ok){ ok++; $('tr[data-refund-id="'+id+'"]').find('.wooqpay-status').text('succeeded'); }
                    else { fail++; $('tr[data-refund-id="'+id+'"]').find('.wooqpay-status').text('error'); }
                });
                alert('Retry complete: ' + ok + ' succeeded, ' + fail + ' failed');
            });
        });
    });
})(jQuery);

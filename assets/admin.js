jQuery(function ($) {
    $('#rog-products').selectWoo({
        width: 'resolve',
        placeholder: 'Search subscription products or variations…',
        minimumInputLength: 2,
        ajax: {
            url: rogAdmin.url,
            dataType: 'json',
            delay: 300,
            data: function (params) {
                return { action: 'rog_search_products', nonce: rogAdmin.nonce, term: params.term };
            },
            processResults: function (data) { return data; },
            cache: true
        }
    });
});

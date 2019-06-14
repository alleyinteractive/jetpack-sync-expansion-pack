jQuery(function ($) {
  $('#jpsep-tools :submit').click(function (e) {
    $('#jpsep-action').val($(this).data('action'));
  });

  function renderResponse(message) {
    $('#jpsep-tools-output').show().html(message);
  }

  $('#jpsep-tools').submit(function(e) {
    console.log(e);
    e.preventDefault();
    $.post({
      url: $(this).attr('action'),
      data: $(this).serialize(),
      success: function(response) {
        renderResponse(response.data)
      },
      error: function(error) {
        renderResponse(error.responseJSON.data);
      },
    });
  });
});

jQuery(function($){
  $('.mirvp-sortable').sortable({
    axis:'y',
    handle:'.mirvp-handle',
    items:'> tr',
    cursor:'move',
    update:function(){
      $(this).children('tr').each(function(index){
        $(this).find('input.mirvp-order').val(index);
      });
    }
  });
});
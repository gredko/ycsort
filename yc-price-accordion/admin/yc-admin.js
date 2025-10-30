jQuery(function($){
  const $b = $("#yc-branches-body");
  $("#yc-add-row").on("click", function(){
    const i = $b.find("tr").length;
    $b.append('<tr><td><input class="regular-text" type="number" min="1" name="yc_branches['+i+'][id]" required></td><td><input class="regular-text" type="text" name="yc_branches['+i+'][title]" required></td><td><input class="regular-text" type="text" placeholder="https://nXXXX.yclients.com/" name="yc_branches['+i+'][url]"></td><td><button type="button" class="button button-secondary yc-remove-row">Удалить</button></td></tr>');
  });
  $b.on("click",".yc-remove-row",function(){
    $(this).closest("tr").remove();
    $b.find("tr").each(function(i,tr){
      $(tr).find("input").each(function(){
        var name = $(this).attr("name");
        name = name.replace(/yc_branches\[\d+\]/, 'yc_branches['+i+']');
        $(this).attr("name", name);
      });
    });
  });
});
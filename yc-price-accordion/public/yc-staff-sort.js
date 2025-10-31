(function(){
  function toNumber(value){
    if (value === undefined || value === null || value === '') {
      return Number.POSITIVE_INFINITY;
    }
    var parsed = parseInt(value, 10);
    return isNaN(parsed) ? Number.POSITIVE_INFINITY : parsed;
  }

  document.addEventListener('DOMContentLoaded', function(){
    try {
      document.querySelectorAll('.yc-staff-grid').forEach(function(grid){
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.yc-staff-card'));
        if (!cards.length) {
          return;
        }
        cards.sort(function(a, b){
          var ao = toNumber(a.getAttribute('data-order'));
          var bo = toNumber(b.getAttribute('data-order'));
          if (ao !== bo) {
            return ao - bo;
          }
          var an = (a.querySelector('.yc-staff-name') || {}).textContent || '';
          var bn = (b.querySelector('.yc-staff-name') || {}).textContent || '';
          an = an.trim().toLowerCase();
          bn = bn.trim().toLowerCase();
          if (an < bn) return -1;
          if (an > bn) return 1;
          return 0;
        });
        var frag = document.createDocumentFragment();
        cards.forEach(function(card){ frag.appendChild(card); });
        grid.appendChild(frag);
      });
    } catch (e) {
      if (window.console && console.warn) {
        console.warn('YC staff sort:', e);
      }
    }
  });
})();

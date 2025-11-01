(function(){
  function isElem(n){ return n && n.nodeType === 1; }
  function hasClass(n, cls){ return isElem(n) && n.classList && n.classList.contains(cls); }
  function parent(n){ return isElem(n) ? n.parentNode : (n && n.parentNode ? n.parentNode : null); }
  function findHeader(el){
    var n = el;
    while(n && !hasClass(n,'yc-acc-header')){ n = parent(n); }
    return n;
  }
  document.addEventListener('click', function(e){
    var header = findHeader(e.target);
    if (header) {
      var panel = header.nextElementSibling;
      var expanded = header.getAttribute('aria-expanded') === 'true';
      var container = header.parentNode ? header.parentNode.parentNode : null;
      if (container){
        var openHeaders = container.querySelectorAll('.yc-acc-header[aria-expanded="true"]');
        for (var i=0;i<openHeaders.length;i++) {
          var b = openHeaders[i];
          if (b !== header) {
            b.setAttribute('aria-expanded','false');
            var p = b.nextElementSibling;
            if (p) { p.setAttribute('aria-hidden','true'); p.style.maxHeight='0px'; }
          }
        }
      }
      header.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      if (panel){
        panel.setAttribute('aria-hidden', expanded ? 'true' : 'false');
        if (!expanded) {
          panel.style.maxHeight = panel.scrollHeight + 'px';
          setTimeout(function(){ panel.style.maxHeight = panel.scrollHeight + 'px'; }, 200);
        } else {
          panel.style.maxHeight = '0px';
        }
      }
      return;
    }
  }, false);
})();
;(function(){
  function sortStaffGrid(root){
    if(!root) return;
    var cards = Array.prototype.slice.call(root.querySelectorAll('.yc-staff-card'));
    if(!cards.length) return;
    cards.sort(function(a,b){
      var av = parseInt(a.getAttribute('data-order')||'0',10);
      var bv = parseInt(b.getAttribute('data-order')||'0',10);
      if(isNaN(av)) av = 0; if(isNaN(bv)) bv = 0;
      if(av===bv) return 0;
      return av - bv;
    });
    cards.forEach(function(el){ root.appendChild(el); });
  }
  function init(){
    var wrap = document.querySelector('.yc-staff-grid') || document.querySelector('.yc-staff-grid-wrap');
    if(wrap) sortStaffGrid(wrap);
  }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();

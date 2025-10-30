(function(){
  function isElem(n){ return n && n.nodeType === 1; }
  function hasClass(n, cls){ return isElem(n) && n.classList && n.classList.contains(cls); }
  function parent(n){ return isElem(n) ? n.parentNode : (n && n.parentNode ? n.parentNode : null); }
  function safeClosest(start, selector){
    var n = start;
    while(n){
     if (isElem(n) && n.matches && n.matches(selector)) return n;
     n = parent(n);
    }
    return null;
  }
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

    var moreBtn = safeClosest(e.target, '.yc-load-more');
    if (moreBtn) {
      var cat = moreBtn.parentNode;
      var list = cat ? cat.querySelector('.yc-services') : null;
      if (!list) return;
      var rest = list.getAttribute('data-rest');
      if (!rest) { moreBtn.remove(); return; }
      var items;
      try { items = JSON.parse(rest); } catch(ex){ moreBtn.remove(); return; }
      var acc = safeClosest(moreBtn, '.yc-accordion');
      var page = parseInt(acc ? (acc.getAttribute('data-page') || '15') : '15', 10);
      var chunk = items.splice(0, page);
      for (var i=0;i<chunk.length;i++) {
        var svc = chunk[i];
        var name = svc.title || svc.name || '';
        var pmin = Number((svc.price_min!=null?svc.price_min:(svc.price!=null?svc.price:0))) || 0;
        var pmax = Number((svc.price_max!=null?svc.price_max:0)) || 0;
        var price = (pmax && pmax!==pmin) ? (pmin.toLocaleString('ru-RU') + '–' + pmax.toLocaleString('ru-RU') + ' ₽') : (pmin.toLocaleString('ru-RU') + ' ₽');
        var li = document.createElement('li');
        li.className = 'yc-service';
        li.innerHTML = '<div class="yc-service-row"><div class="yc-service-name"></div><div class="yc-service-right"><div class="yc-service-price"></div></div></div>';
        li.querySelector('.yc-service-name').textContent = name;
        li.querySelector('.yc-service-price').textContent = price;
        list.appendChild(li);
      }
      if (items.length>0) { list.setAttribute('data-rest', JSON.stringify(items)); } else { list.removeAttribute('data-rest'); moreBtn.remove(); }
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

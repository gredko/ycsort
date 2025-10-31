(function(){
  function norm(s){return (s||'').toString().trim().toLowerCase().replace(/\s+/g,' ');}

  document.addEventListener('DOMContentLoaded', function(){
    try{
      var cfg = (window.YCStaffOrder||{});
      var adminOrderRaw = (cfg.adminOrder||'').toString();
      var adminOrderMap = {}; adminOrderRaw.split(/\r?\n|,/).forEach(function(line){ var p=line.split('='); if(p.length>=2){ var n=(p[0]||'').trim().toLowerCase().replace(/\s+/g,' '); var v=parseFloat(p.slice(1).join('=')); if(n&&isFinite(v)) adminOrderMap[n]=v; }});
      var alphaRest = !!cfg.alphaRest;
      var orderRaw = (cfg.order||'').toString();

      var orderList = orderRaw.split(/\r?\n|,/).map(function(x){return norm(x);}).filter(Boolean);
      var orderWeight = {}; orderList.forEach(function(name,i){ orderWeight[name]=i; });

      document.querySelectorAll('.yc-staff-grid').forEach(function(grid){
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.yc-staff-card'));
        if(cards.length===0) return;

        function getName(card){
          var n = card.querySelector('.yc-staff-name');
          if(!n) return '';
          var t = n.textContent || n.innerText || '';
          return norm(t);
        }

        cards.sort(function(a,b){
          var an = getName(a), bn = getName(b);
          var aadmin = (an in adminOrderMap) ? adminOrderMap[an] : 1e9;
          var badmin = (bn in adminOrderMap) ? adminOrderMap[bn] : 1e9;
          if(aadmin!==badmin) return aadmin - badmin;

          var ai = (an in orderWeight) ? orderWeight[an] : 1e9;
          var bi = (bn in orderWeight) ? orderWeight[bn] : 1e9;
          if(ai!==bi) return ai - bi;

          if(alphaRest){
            if(an<bn) return -1;
            if(an>bn) return 1;
          }
          return 0;
        });

        var frag = document.createDocumentFragment();
        cards.forEach(function(c){ frag.appendChild(c); });
        grid.appendChild(frag);
      });
    }catch(e){ if(window.console&&console.warn) console.warn('YC staff sort:', e); }
  });
})();
;(()=>{
  var cfg=window.YCStaffOrder||{};
  var raw=(cfg.adminOrder||'').toString();
  var map={};
  raw.split(/\r?\n|,/).forEach(function(line){
    var p=line.split('=');
    if(p.length>=2){
      var n=(p[0]||'').trim().toLowerCase().replace(/\s+/g,' ');
      var v=parseFloat(p.slice(1).join('='));
      if(n&&isFinite(v)) map[n]=v;
    }
  });
  var grid=document.querySelector('.yc-staff-grid'); if(!grid) return;
  var cards=[].slice.call(grid.querySelectorAll('.yc-staff-card'));
  cards.sort(function(a,b){
    var an=(a.querySelector('.yc-staff-name')||{}).textContent||''; an=an.trim().toLowerCase().replace(/\s+/g,' ');
    var bn=(b.querySelector('.yc-staff-name')||{}).textContent||''; bn=bn.trim().toLowerCase().replace(/\s+/g,' ');
    var ao=(an in map)?map[an]:1e9, bo=(bn in map)?map[bn]:1e9;
    if(ao!==bo) return ao-bo;
    return an.localeCompare(bn,'ru');
  });
  cards.forEach(function(c){grid.appendChild(c)});
})();
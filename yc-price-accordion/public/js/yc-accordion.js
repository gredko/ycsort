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
;(function(){
  function ready(fn){
    if (document.readyState === 'loading'){
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }
  function normalize(value){
    return (value || '').toString().toLowerCase().replace(/\s+/g, ' ').trim();
  }
  function setExpanded(header, panel, expanded){
    if (!header || !panel){
      return;
    }
    header.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    panel.setAttribute('aria-hidden', expanded ? 'false' : 'true');
    if (expanded){
      panel.style.maxHeight = panel.scrollHeight + 'px';
      setTimeout(function(){ panel.style.maxHeight = panel.scrollHeight + 'px'; }, 50);
    } else {
      panel.style.maxHeight = '0px';
    }
  }
  ready(function(){
    var containers = document.querySelectorAll('.yc-price-wrapper');
    if (!containers.length){
      return;
    }
    containers.forEach(function(container){
      var input = container.querySelector('.yc-service-search');
      if (!input){
        return;
      }
      var message = container.querySelector('.yc-search-empty');
      var branches = Array.prototype.slice.call(container.querySelectorAll('.yc-acc-item')).map(function(item){
        var header = item.querySelector('.yc-acc-header');
        var panel = header ? header.nextElementSibling : null;
        var categories = Array.prototype.slice.call(item.querySelectorAll('.yc-cat')).map(function(category){
          var services = Array.prototype.slice.call(category.querySelectorAll('.yc-service')).map(function(service){
            return {
              element: service,
              haystack: normalize(service.getAttribute('data-search'))
            };
          });
          return {
            element: category,
            services: services
          };
        });
        return {
          element: item,
          header: header,
          panel: panel,
          categories: categories
        };
      });

      var filterServices = function(){
        var query = normalize(input.value);
        var searching = query.length > 0;
        var wasActive = container.getAttribute('data-search-active') === '1';
        if (searching && !wasActive){
          container.setAttribute('data-search-active', '1');
          branches.forEach(function(branch){
            if (branch.header && !branch.header.hasAttribute('data-prev-expanded')){
              branch.header.setAttribute('data-prev-expanded', branch.header.getAttribute('aria-expanded') || 'false');
            }
          });
        } else if (!searching && wasActive){
          container.setAttribute('data-search-active', '0');
        }

        var totalMatches = 0;
        branches.forEach(function(branch){
          var branchMatches = 0;

          branch.categories.forEach(function(category){
            var categoryMatches = 0;
            category.services.forEach(function(service){
              var isMatch = !searching || (service.haystack && service.haystack.indexOf(query) !== -1);
              service.element.style.display = isMatch ? '' : 'none';
              if (isMatch){
                categoryMatches++;
              }
            });

            if (searching){
              category.element.style.display = categoryMatches > 0 ? '' : 'none';
            } else {
              category.element.style.display = '';
            }

            branchMatches += categoryMatches;
          });

          if (searching){
            branch.element.style.display = branchMatches > 0 ? '' : 'none';
            if (branch.header && branch.panel){
              setExpanded(branch.header, branch.panel, branchMatches > 0);
            }
          } else {
            branch.element.style.display = '';
            if (wasActive && branch.header && branch.panel){
              var prev = branch.header.getAttribute('data-prev-expanded');
              var prevExpanded = prev === 'true';
              setExpanded(branch.header, branch.panel, prevExpanded);
              if (branch.header){
                branch.header.removeAttribute('data-prev-expanded');
              }
            }
          }

          totalMatches += branchMatches;
        });

        if (!searching && wasActive){
          branches.forEach(function(branch){
            if (branch.header){
              branch.header.removeAttribute('data-prev-expanded');
            }
          });
        }

        if (message){
          if (searching && totalMatches === 0){
            message.removeAttribute('hidden');
          } else {
            message.setAttribute('hidden', 'hidden');
          }
        }
      };

      input.addEventListener('input', filterServices);
      input.addEventListener('search', filterServices);
      filterServices();
    });
  });
})();

jQuery(function($){
  const $tabs = $('#yc-settings-tabs');
  if ($tabs.length){
    const $buttons = $tabs.find('.yc-tab-button');
    const $panels = $tabs.find('.yc-tab-panel');
    $buttons.on('click', function(){
      const tab = $(this).data('tab');
      $buttons.removeClass('active');
      $(this).addClass('active');
      $panels.removeClass('active');
      $panels.filter('[data-tab="'+tab+'"]').addClass('active');
    });
  }

  const $branchesBody = $('#yc-branches-body');
  const templateRow = function(index){
    return '<tr>'+
      '<td><input type="number" min="1" name="yc_branches['+index+'][id]" required /></td>'+
      '<td><input type="text" class="regular-text" name="yc_branches['+index+'][title]" required /></td>'+
      '<td><input type="text" class="regular-text" name="yc_branches['+index+'][url]" placeholder="https://example.yclients.com/" /></td>'+
      '<td><button type="button" class="button button-secondary yc-remove-row">&times;</button></td>'+
    '</tr>';
  };

  $('#yc-add-branch').on('click', function(){
    const index = $branchesBody.find('tr').length;
    $branchesBody.append(templateRow(index));
  });

  $branchesBody.on('click', '.yc-remove-row', function(){
    $(this).closest('tr').remove();
    $branchesBody.find('tr').each(function(idx){
      $(this).find('input').each(function(){
        const name = $(this).attr('name').replace(/yc_branches\[[0-9]+\]/, 'yc_branches['+idx+']');
        $(this).attr('name', name);
      });
    });
  });

  const syncConfig = window.ycPaAdmin || {};
  const $syncButton = $('#yc-sync-start');
  const $progressBox = $('#yc-sync-progress');
  const $progressBar = $progressBox.find('.yc-progress-bar span');
  const $progressMessage = $progressBox.find('.yc-sync-message');
  const $log = $('#yc-sync-log');
  const $status = $('.yc-sync-status');

  function setProgress(percent, message){
    $progressBox.removeAttr('hidden');
    $progressBar.css('width', Math.min(100, Math.max(0, percent)) + '%');
    if (message){
      $progressMessage.text(message);
    }
  }

  function appendLog(text, type){
    const $item = $('<div class="yc-log-row"></div>').text(text || '');
    if (type){
      $item.addClass('is-' + type);
    }
    $log.prepend($item);
  }

  function setSyncState(running){
    if (running){
      $syncButton.prop('disabled', true).text(syncConfig.i18n ? syncConfig.i18n.syncing : '...');
    } else {
      $syncButton.prop('disabled', false).text(syncConfig.i18n ? syncConfig.i18n.buttonStart : 'Sync');
    }
  }

  async function fetchStatus(){
    const url = syncConfig.restUrl + '?t=' + Date.now();
    const resp = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': syncConfig.nonce || ''
      }
    });
    if (!resp.ok){
      throw new Error('HTTP ' + resp.status);
    }
    const json = await resp.json();
    if (json && json.error){
      throw new Error(json.error);
    }
    return json;
  }

  async function syncBranch(branch, index, total){
    const body = {
      mode: 'branch',
      company_id: branch.id,
      download_photos: true
    };
    const resp = await fetch(syncConfig.restUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': syncConfig.nonce || ''
      },
      body: JSON.stringify(body)
    });
    if (!resp.ok){
      throw new Error('HTTP ' + resp.status);
    }
    const json = await resp.json();
    const branchName = branch.title || ('ID ' + branch.id);
    const done = index + 1;
    const percent = Math.round((done / total) * 100);
    const stats = json.result && json.result.stats ? json.result.stats : {};
    const errors = json.result && json.result.errors ? json.result.errors : [];
    let message = branchName + ': ' + (stats.services || 0) + ' services, ' + (stats.staff || 0) + ' staff';
    if (errors && errors.length){
      message += ' — ' + errors.join('; ');
      appendLog(message, 'error');
    } else {
      appendLog(message, 'success');
    }
    setProgress(percent, message);
    return json;
  }

  if ($syncButton.length){
    $syncButton.on('click', async function(){
      setSyncState(true);
      $log.empty();
      try {
        const status = await fetchStatus();
        const branches = (status && status.branches) ? status.branches.filter(function(b){ return b && b.id; }) : [];
        if (!branches.length){
          throw new Error('No branches configured');
        }
        setProgress(0, syncConfig.i18n ? syncConfig.i18n.syncing : 'Syncing…');
        let completed = 0;
        for (const branch of branches){
          await syncBranch(branch, completed, branches.length);
          completed++;
        }
        setProgress(100, syncConfig.i18n ? syncConfig.i18n.done : 'Done');
        appendLog(syncConfig.i18n ? syncConfig.i18n.done : 'Done', 'success');
        const updated = await fetchStatus();
        if (updated && typeof updated.last_sync !== 'undefined'){
          const date = updated.last_sync ? new Date(updated.last_sync * 1000).toLocaleString() : '';
          if (date){
            const label = $status.data('label') || $status.text().split(':')[0];
            $status.html('<strong>'+ label +':</strong> ' + date);
          }
        }
      } catch (error){
        appendLog((syncConfig.i18n ? syncConfig.i18n.error : 'Sync error') + ': ' + error.message, 'error');
        setProgress(0, syncConfig.i18n ? syncConfig.i18n.error : 'Error');
      }
      setSyncState(false);
    });
  }
});

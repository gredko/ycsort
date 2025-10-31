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
    return await resp.json();
  }

  const t = function(key, fallback){
    if (syncConfig.i18n && syncConfig.i18n[key]){
      return syncConfig.i18n[key];
    }
    return fallback;
  };

  async function syncBranch(branch, index, total){
    const branchName = branch.title || ('ID ' + branch.id);
    const staffPhotoBatch = parseInt(syncConfig.staffPhotosBatch, 10) || 5;
    const servicesBatch = parseInt(syncConfig.servicesBatch, 10) || 50;
    let branchProgress = 0;
    const errors = [];

    const updateProgress = function(progress, message){
      branchProgress = Math.max(branchProgress, Math.min(1, progress));
      const percent = Math.round(((index + branchProgress) / total) * 100);
      setProgress(percent, message);
    };

    const request = async function(payload){
      const body = Object.assign({
        company_id: branch.id
      }, payload || {});
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
      if (json && json.code){
        throw new Error(json.message || json.code);
      }
      if (json && json.result && Array.isArray(json.result.errors) && json.result.errors.length){
        errors.push.apply(errors, json.result.errors);
      }
      return json;
    };

    updateProgress(0, branchName + ': ' + t('stageStart', 'Подготовка…'));

    const staffList = await request({
      mode: 'staff_list'
    });
    const staffStats = (staffList.result && staffList.result.stats) ? staffList.result.stats : {};
    const staffCount = staffStats.staff || 0;
    updateProgress(0.2, branchName + ': ' + t('stageStaffListDone', 'Сотрудники обновлены') + ' (' + staffCount + ')');

    let photosProcessed = 0;
    let staffOffset = 0;
    let staffTotal = staffList.result && staffList.result.state && typeof staffList.result.state.total !== 'undefined'
      ? staffList.result.state.total
      : staffCount;
    if (!staffTotal || staffTotal < staffCount){
      staffTotal = staffCount;
    }

    let staffDone = staffTotal === 0;
    while (!staffDone){
      const photoResp = await request({
        mode: 'staff_photos',
        offset: staffOffset,
        limit: staffPhotoBatch
      });
      const state = photoResp.result && photoResp.result.state ? photoResp.result.state : {};
      const processed = state.processed || 0;
      photosProcessed += processed;
      staffOffset = typeof state.next_offset !== 'undefined' ? state.next_offset : (staffOffset + processed);
      if (typeof state.total !== 'undefined' && state.total !== null){
        staffTotal = state.total;
      }
      const completed = typeof state.completed !== 'undefined' ? state.completed : staffOffset;
      const denominator = staffTotal && staffTotal > 0 ? staffTotal : (completed > 0 ? completed : 1);
      const ratio = Math.min(1, denominator ? completed / denominator : 1);
      updateProgress(0.2 + 0.3 * ratio, branchName + ': ' + t('stageStaffPhotos', 'Загрузка фото сотрудников') + ' (' + completed + '/' + (denominator || '?') + ')');
      staffDone = !!state.done || processed === 0 || (staffTotal && staffOffset >= staffTotal);
    }

    updateProgress(0.5, branchName + ': ' + t('stageStaffPhotosDone', 'Фото сотрудников обновлены') + ' (' + photosProcessed + ')');

    const servicesInit = await request({
      mode: 'services_init',
      limit: servicesBatch
    });
    let servicesState = servicesInit.result && servicesInit.result.state ? servicesInit.result.state : {};
    let servicesTotal = typeof servicesState.total !== 'undefined' && servicesState.total !== null ? servicesState.total : null;
    let servicesProcessed = typeof servicesState.completed !== 'undefined' ? servicesState.completed : (servicesInit.result && servicesInit.result.stats && servicesInit.result.stats.services ? servicesInit.result.stats.services : 0);
    const updateServicesProgress = function(){
      const totalValue = servicesTotal && servicesTotal > 0 ? servicesTotal : (servicesProcessed > 0 ? servicesProcessed : 1);
      const ratio = Math.min(1, totalValue ? servicesProcessed / totalValue : 1);
      updateProgress(0.5 + 0.5 * ratio, branchName + ': ' + t('stageServices', 'Загрузка услуг') + ' (' + servicesProcessed + '/' + (totalValue || '?') + ')');
    };
    updateServicesProgress();

    let hasMore = !!servicesState.has_more;
    let nextPage = servicesState.next_page || (hasMore ? 2 : null);

    while (hasMore && nextPage){
      const batchResp = await request({
        mode: 'services_batch',
        page: nextPage,
        limit: servicesBatch
      });
      servicesState = batchResp.result && batchResp.result.state ? batchResp.result.state : {};
      if (typeof servicesState.total !== 'undefined' && servicesState.total !== null){
        servicesTotal = servicesState.total;
      }
      if (typeof servicesState.completed !== 'undefined'){
        servicesProcessed = servicesState.completed;
      } else {
        servicesProcessed += servicesState.processed || 0;
      }
      updateServicesProgress();
      hasMore = !!servicesState.has_more;
      nextPage = servicesState.next_page || (hasMore ? (nextPage + 1) : null);
    }

    updateProgress(1, branchName + ': ' + t('stageDone', 'Синхронизация завершена'));

    const summary = branchName + ': ' + (servicesProcessed || 0) + ' ' + t('labelServices', 'услуг') + ', ' + (staffCount || 0) + ' ' + t('labelStaff', 'сотрудников') + ', ' + (photosProcessed || 0) + ' ' + t('labelPhotos', 'фото');
    if (errors.length){
      appendLog(summary + ' — ' + errors.join('; '), 'error');
    } else {
      appendLog(summary, 'success');
    }

    return {
      staff: staffCount,
      photos: photosProcessed,
      services: servicesProcessed,
      errors: errors
    };
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

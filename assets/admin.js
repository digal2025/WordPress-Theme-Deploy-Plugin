(function ($) {
  'use strict';

  var $repo      = $('#ghad_repo');
  var $sshUrl    = $('#ghad_repo_ssh_url');
  var $refresh   = $('#ghad_refresh_repos');
  var $saveRepo  = $('#ghad_save_repo');
  var $setupKey  = $('#ghad_setup_key');
  var $createHook = $('#ghad_create_hook');
  var $keyStatus = $('#ghad_key_status');
  var $hookStatus = $('#ghad_hook_status');

  function fetchRepos() {
    $refresh.prop('disabled', true).text('Loading...');
    $.post(ghad.ajax_url, {
      action: 'ghad_fetch_repos',
      _ajax_nonce: ghad.nonce_fetch_repos
    }, function (res) {
      if (res.success) {
        var selected = $repo.val();
        $repo.find('option:not([value=""])').remove();
        $.each(res.data, function (i, r) {
          $repo.append($('<option>', {
            value: r.name,
            text: r.name + (r.private ? ' 🔒' : ''),
            'data-ssh': r.ssh_url
          }));
        });
        if (selected) {
          $repo.val(selected);
          var match = res.data.find(function (r) { return r.name === selected; });
          if (match) $sshUrl.val(match.ssh_url);
        }
      } else {
        $keyStatus.text('Failed to load repos: ' + res.data).addClass('ghad-error');
      }
    }).always(function () {
      $refresh.prop('disabled', false).text('Refresh');
    });
  }

  $repo.on('change', function () {
    var opt = $(this).find(':selected');
    $sshUrl.val(opt.data('ssh') || '');
  });

  $refresh.on('click', fetchRepos);

  if ($repo.length && $repo.find('option').length <= 1) {
    fetchRepos();
  }

  $saveRepo.on('click', function () {
    $saveRepo.prop('disabled', true).text('Saving...');
    var $form = $saveRepo.closest('.wrap').find('form:has(#ghad_client_id)');
    if ($form.length) {
      $form.submit();
    } else {
      window.location.reload();
    }
  });

  $setupKey.on('click', function () {
    $setupKey.prop('disabled', true);
    $keyStatus.removeClass('ghad-success ghad-error').text('Generating key and adding to GitHub...');
    $.post(ghad.ajax_url, {
      action: 'ghad_setup_deploy_key',
      _ajax_nonce: ghad.nonce_setup_key
    }, function (res) {
      if (res.success) {
        $keyStatus.text('Deploy key "' + res.data.title + '" installed.').addClass('ghad-success');
      } else {
        $keyStatus.text('Error: ' + res.data).addClass('ghad-error');
      }
    }).always(function () {
      $setupKey.prop('disabled', false);
    });
  });

  $createHook.on('click', function () {
    $createHook.prop('disabled', true);
    $hookStatus.removeClass('ghad-success ghad-error').text('Creating webhook on GitHub...');
    $.post(ghad.ajax_url, {
      action: 'ghad_create_webhook',
      _ajax_nonce: ghad.nonce_create_hook
    }, function (res) {
      if (res.success) {
        $hookStatus.text('Webhook created (ID: ' + res.data.id + ')').addClass('ghad-success');
      } else {
        $hookStatus.text('Error: ' + res.data).addClass('ghad-error');
      }
    }).always(function () {
      $createHook.prop('disabled', false);
    });
  });

})(jQuery);

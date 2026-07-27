(function ($) {
  'use strict';

  var $repo         = $('#ghad_repo');
  var $sshUrl       = $('#ghad_repo_ssh_url');
  var $branch       = $('#ghad_branch');
  var $localPath    = $('#ghad_local_path');
  var $refresh      = $('#ghad_refresh_repos');
  var $saveRepo     = $('#ghad_save_repo');
  var $repoStatus   = $('#ghad_repo_status');
  var $setupKey     = $('#ghad_setup_key');
  var $createHook   = $('#ghad_create_hook');
  var $keyStatus    = $('#ghad_key_status');
  var $hookStatus   = $('#ghad_hook_status');
  var $detectPath   = $('#ghad_detect_path');
  var $themeList    = $('#ghad_theme_list');
  var $refreshStatus = $('#ghad_refresh_status');
  var $detectStatus = $('#ghad_detect_status');

  function showStatus($el, text, type, persist) {
    $el.text(text).removeClass('ghad-success ghad-error');
    if (type) $el.addClass('ghad-' + type);
    if (!persist && type) {
      setTimeout(function () {
        $el.fadeOut(400, function () {
          $el.text('').removeClass('ghad-success ghad-error').show();
        });
      }, 4000);
    }
  }

  function fetchRepos() {
    $refresh.prop('disabled', true).text('Loading...');
    showStatus($refreshStatus, 'Loading repos...');
    $.post(ghad.ajax_url, {
      action: 'ghad_fetch_repos',
      _ajax_nonce: ghad.nonce_fetch_repos
    }, function (res) {
      if (res.success) {
        var selected = $repo.data('selected') || '';
        var count = res.data.length;
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
          var opt = $repo.find(':selected');
          $sshUrl.val(opt.data('ssh') || '');
        }
        showStatus($refreshStatus, count + ' repos loaded.', 'success');
      } else {
        showStatus($refreshStatus, 'Failed: ' + res.data, 'error', true);
      }
    }).fail(function (xhr) {
      showStatus($refreshStatus, 'Network error. Check your connection.', 'error', true);
    }).always(function () {
      $refresh.prop('disabled', false).text('Refresh');
    });
  }

  function saveRepoSettings() {
    var missing = [];
    if (!$repo.val()) missing.push('repo');
    if (!$branch.val()) missing.push('branch');
    if (!$localPath.val()) missing.push('local path');

    if (missing.length) {
      showStatus($repoStatus, 'Please fill in: ' + missing.join(', ') + '.', 'error', true);
      return;
    }

    $saveRepo.prop('disabled', true);
    showStatus($repoStatus, 'Saving...');
    $.post(ghad.ajax_url, {
      action: 'ghad_save_repo',
      _ajax_nonce: ghad.nonce_save_repo,
      repo_full_name: $repo.val(),
      repo_ssh_url: $sshUrl.val(),
      branch: $branch.val(),
      local_path: $localPath.val()
    }, function (res) {
      if (res.success) {
        showStatus($repoStatus, 'Saved!', 'success');
        $repo.data('selected', $repo.val());
      } else {
        showStatus($repoStatus, 'Error: ' + res.data, 'error', true);
      }
    }).fail(function () {
      showStatus($repoStatus, 'Network error. Could not save.', 'error', true);
    }).always(function () {
      $saveRepo.prop('disabled', false);
    });
  }

  $repo.on('change', function () {
    var opt = $(this).find(':selected');
    $sshUrl.val(opt.data('ssh') || '');
  });

  $refresh.on('click', fetchRepos);

  if ($repo.length) {
    fetchRepos();
  }

  $saveRepo.on('click', saveRepoSettings);

  $detectPath.on('click', function () {
    $detectPath.prop('disabled', true);
    $themeList.empty().hide();
    showStatus($detectStatus, 'Scanning themes...');
    $.post(ghad.ajax_url, {
      action: 'ghad_detect_themes',
      _ajax_nonce: ghad.nonce_detect_themes
    }, function (res) {
      if (res.success) {
        if (!res.data.themes.length) {
          showStatus($detectStatus, 'No themes found.', 'error', true);
          return;
        }
        showStatus($detectStatus, res.data.themes.length + ' themes found. Click one to set path.', 'success', true);
        $.each(res.data.themes, function (i, theme) {
          $themeList.append(
            $('<a>', {
              href: '#',
              'data-path': theme.path,
              text: theme.name + ' (' + theme.slug + ')',
              style: 'display:block;padding:6px 10px;text-decoration:none;border-bottom:1px solid #ddd;color:#2271b1;'
            }).on('click', function (e) {
              e.preventDefault();
              $localPath.val(theme.path);
              showStatus($detectStatus, 'Path set: ' + theme.path, 'success');
              $themeList.hide();
            })
          );
        });
        $themeList.show();
      } else {
        showStatus($detectStatus, 'Failed: ' + res.data, 'error', true);
      }
    }).fail(function () {
      showStatus($detectStatus, 'Network error.', 'error', true);
    }).always(function () {
      $detectPath.prop('disabled', false);
    });
  });

  $(document).on('click', function (e) {
    if (!$(e.target).closest('#ghad_detect_path, #ghad_theme_list, #ghad_detect_status').length) {
      $themeList.hide();
    }
  });

  $setupKey.on('click', function () {
    $setupKey.prop('disabled', true);
    showStatus($keyStatus, 'Generating key and adding to GitHub...');
    $.post(ghad.ajax_url, {
      action: 'ghad_setup_deploy_key',
      _ajax_nonce: ghad.nonce_setup_key
    }, function (res) {
      if (res.success) {
        showStatus($keyStatus, 'Deploy key "' + res.data.title + '" installed.', 'success');
      } else {
        showStatus($keyStatus, 'Error: ' + res.data, 'error', true);
      }
    }).fail(function () {
      showStatus($keyStatus, 'Network error. Key not created.', 'error', true);
    }).always(function () {
      $setupKey.prop('disabled', false);
    });
  });

  $createHook.on('click', function () {
    $createHook.prop('disabled', true);
    showStatus($hookStatus, 'Creating webhook on GitHub...');
    $.post(ghad.ajax_url, {
      action: 'ghad_create_webhook',
      _ajax_nonce: ghad.nonce_create_hook
    }, function (res) {
      if (res.success) {
        showStatus($hookStatus, 'Webhook created (ID: ' + res.data.id + ').', 'success');
      } else {
        showStatus($hookStatus, 'Error: ' + res.data, 'error', true);
      }
    }).fail(function () {
      showStatus($hookStatus, 'Network error. Webhook not created.', 'error', true);
    }).always(function () {
      $createHook.prop('disabled', false);
    });
  });

})(jQuery);

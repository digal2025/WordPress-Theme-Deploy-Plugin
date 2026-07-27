(function ($) {
  'use strict';

  var $repo        = $('#ghad_repo');
  var $sshUrl      = $('#ghad_repo_ssh_url');
  var $branch      = $('#ghad_branch');
  var $localPath   = $('#ghad_local_path');
  var $refresh     = $('#ghad_refresh_repos');
  var $saveRepo    = $('#ghad_save_repo');
  var $repoStatus  = $('#ghad_repo_status');
  var $setupKey    = $('#ghad_setup_key');
  var $createHook  = $('#ghad_create_hook');
  var $keyStatus   = $('#ghad_key_status');
  var $hookStatus  = $('#ghad_hook_status');
  var $detectPath  = $('#ghad_detect_path');
  var $themeList   = $('#ghad_theme_list');

  function fetchRepos() {
    $refresh.prop('disabled', true).text('Loading...');
    $.post(ghad.ajax_url, {
      action: 'ghad_fetch_repos',
      _ajax_nonce: ghad.nonce_fetch_repos
    }, function (res) {
      if (res.success) {
        var selected = $repo.data('selected') || '';
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
      } else {
        $repoStatus.text('Failed to load repos: ' + res.data).addClass('ghad-error');
      }
    }).always(function () {
      $refresh.prop('disabled', false).text('Refresh');
    });
  }

  function saveRepoSettings() {
    $saveRepo.prop('disabled', true);
    $repoStatus.text('Saving...').removeClass('ghad-success ghad-error');
    $.post(ghad.ajax_url, {
      action: 'ghad_save_repo',
      _ajax_nonce: ghad.nonce_save_repo,
      repo_full_name: $repo.val(),
      repo_ssh_url: $sshUrl.val(),
      branch: $branch.val(),
      local_path: $localPath.val()
    }, function (res) {
      if (res.success) {
        $repoStatus.text('Repo settings saved.').addClass('ghad-success');
        $repo.data('selected', $repo.val());
        $repoStatus.fadeOut(3000, function () {
          $repoStatus.removeClass('ghad-success ghad-error').text('');
        });
      } else {
        $repoStatus.text('Error: ' + res.data).addClass('ghad-error');
      }
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
    $themeList.empty();
    $.post(ghad.ajax_url, {
      action: 'ghad_detect_themes',
      _ajax_nonce: ghad.nonce_detect_themes
    }, function (res) {
      if (res.success) {
        var themeRoot = res.data.theme_root;
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
              $themeList.hide();
            })
          );
        });
        $themeList.show();
      } else {
        alert('Failed to detect themes: ' + res.data);
      }
    });
  });

  $(document).on('click', function (e) {
    if (!$(e.target).closest('#ghad_detect_path, #ghad_theme_list').length) {
      $themeList.hide();
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

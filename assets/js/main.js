// ===== INSTALLMENT BUSINESS - CUSTOM THEME =====

$(document).ready(function () {

  // ===== MOBILE SIDEBAR TOGGLE =====
  $('#sidebarMobileToggle').click(function () {
    $('#sidebar').toggleClass('show');
    $('#sidebarBackdrop').toggleClass('show');
  });

  $('#sidebarBackdrop').click(function () {
    $('#sidebar').removeClass('show');
    $(this).removeClass('show');
  });

  // ===== DESKTOP SIDEBAR TOGGLE =====
  $('#sidebarToggle').click(function () {
    $('#sidebar').toggleClass('collapsed');
    var icon = $(this).find('i');
    var label = $(this).find('.toggle-label');
    if ($('#sidebar').hasClass('collapsed')) {
      icon.removeClass('fa-chevron-left').addClass('fa-chevron-right');
      label.text('Expand');
    } else {
      icon.removeClass('fa-chevron-right').addClass('fa-chevron-left');
      label.text('Collapse');
    }
  });

  // ===== SIDEBAR COLLAPSE ARROW =====
  $('.sidebar .nav-link[data-toggle="collapse"]').click(function (e) {
    var target = $($(this).attr('href'));
    $(this).find('.arrow i').toggleClass('fa-chevron-right fa-chevron-down');
  });

  // ===== AUTO-EXPAND ACTIVE SUBMENU =====
  $('.collapse-item.active').each(function () {
    var collapse = $(this).closest('.collapse');
    if (collapse.length) {
      collapse.addClass('show');
      var toggler = $('[href="#' + collapse.attr('id') + '"]');
      if (toggler.length) {
        toggler.find('.arrow i').removeClass('fa-chevron-right').addClass('fa-chevron-down');
      }
    }
  });

  // ===== SCROLL TO TOP =====
  $(window).scroll(function () {
    if ($(this).scrollTop() > 200) {
      $('#scrollToTop').addClass('show');
    } else {
      $('#scrollToTop').removeClass('show');
    }
  });

  $('#scrollToTop').click(function (e) {
    e.preventDefault();
    $('html, body').animate({ scrollTop: 0 }, 300);
  });

  // ===== AUTO-DISMISS ALERTS =====
  setTimeout(function () {
    $('.alert-dismissible').fadeOut('slow');
  }, 5000);
});








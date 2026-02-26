/* ============================================================
   Trias Partner Portal — Main JS
   ============================================================ */

$(document).ready(function () {

  // ---- Sidebar Toggle ----
  const sidebar = document.getElementById('sidebar');
  const mainContent = document.querySelector('.main-content');
  const topNavbar = document.getElementById('topNavbar');
  const toggleBtn = document.getElementById('sidebarToggle');
  const isMobile = () => window.innerWidth < 769;

  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', () => {
      if (isMobile()) {
        sidebar.classList.toggle('mobile-open');
      } else {
        sidebar.classList.toggle('collapsed');
        mainContent && mainContent.classList.toggle('expanded');
        topNavbar && topNavbar.classList.toggle('navbar-collapsed');
      }
    });

    // Close sidebar on mobile overlay click
    document.addEventListener('click', (e) => {
      if (isMobile() && sidebar.classList.contains('mobile-open') &&
        !sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
        sidebar.classList.remove('mobile-open');
      }
    });
  }

  // ---- DataTables Init ----
  if ($.fn.DataTable) {
    $('table.datatable').each(function () {
      if (!$.fn.DataTable.isDataTable(this)) {
        $(this).DataTable({
          pageLength: 25,
          language: {
            search: '',
            searchPlaceholder: 'Search...',
            lengthMenu: 'Show _MENU_ entries',
            info: 'Showing _START_ to _END_ of _TOTAL_ records',
            emptyTable: 'No records found',
            zeroRecords: 'No matching records found',
          },
          columnDefs: [
            { orderable: false, targets: -1 } // Disable sort on last (actions) column
          ],
          order: [],
        });
      }
    });
  }

  // ---- Select2 Init ----
  if ($.fn.select2) {
    $('.select2').select2({
      theme: 'bootstrap-5',
      width: '100%',
      allowClear: true,
      placeholder: function () {
        return $(this).data('placeholder') || 'Select...';
      }
    });
  }

  // ---- Auto-dismiss flash alerts ----
  setTimeout(() => {
    $('.alert.alert-success, .alert.alert-info').each(function () {
      const alert = bootstrap.Alert.getOrCreateInstance(this);
      if (alert) alert.close();
    });
  }, 4000);

  // ---- Confirm delete buttons ----
  $('form').on('submit', function (e) {
    const confirmMsg = $(this).data('confirm');
    if (confirmMsg && !confirm(confirmMsg)) {
      e.preventDefault();
    }
  });

  // ---- Tooltip init ----
  $('[title]').tooltip({ trigger: 'hover', placement: 'top' });

  // ---- Reset modal forms on close ----
  $('.modal').on('hidden.bs.modal', function () {
    const form = $(this).find('form')[0];
    if (form) {
      form.reset();
      // Reset hidden id fields to 0
      $(this).find('input[type="hidden"][name="id"]').val('0');
      // Reset modal title
      const title = $(this).find('.modal-title');
      if (title.length) {
        const original = title.data('original');
        if (original) title.text(original);
      }
      // Reset Select2
      if ($.fn.select2) {
        $(this).find('.select2').val('').trigger('change');
      }
    }
  });

  // Store original modal titles
  $('.modal-title').each(function () {
    $(this).data('original', $(this).text());
  });

  // ---- Phone number formatter ----
  $('input[name="phone"]').on('input', function () {
    this.value = this.value.replace(/[^0-9+\-\s()]/g, '');
  });

});

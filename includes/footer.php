      </div>
      <!-- /.content -->

      <!-- Footer -->
      <footer class="sticky-footer">
        <div class="container">
          <div class="copyright text-center">
            &copy; <?= date('Y') ?> RAYHAAN MOBILE KAHUTA. All rights reserved.
          </div>
        </div>
      </footer>
    </div>
    <!-- End of Content Wrapper -->
  </div>
  <!-- End of Wrapper -->

  <!-- Scroll to Top -->
  <a class="scroll-to-top" href="#page-top" id="scrollToTop">
    <i class="fas fa-angle-up"></i>
  </a>

  <!-- Logout Modal -->
  <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Ready to Leave?</h5>
          <button class="close" type="button" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">Select "Logout" to end your session.</div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
          <a class="btn btn-primary" href="<?= $base_url ?? '' ?>login.php?logout=1">Logout</a>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      flatpickr('.datepicker', { dateFormat: 'Y-m-d', allowInput: true });
    });
  </script>
  <script src="<?= $base_url ?? '' ?>assets/js/main.js"></script>
</body>
</html>

<!-- BEGIN: Theme CSS-->
<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Rubik:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="{{ asset(mix('assets/vendor/fonts/boxicons.css')) }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/fonts/fontawesome.css')) }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/fonts/flag-icons.css')) }}" />

<!-- Core CSS -->
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/css' .$configData['rtlSupport'] .'/core' .($configData['style'] !== 'light' ? '-' . $configData['style'] : '') .'.css')) }}" class="{{ $configData['hasCustomizer'] ? 'template-customizer-core-css' : '' }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/css' .$configData['rtlSupport'] .'/' .$configData['theme'] .($configData['style'] !== 'light' ? '-' . $configData['style'] : '') .'.css')) }}" class="{{ $configData['hasCustomizer'] ? 'template-customizer-theme-css' : '' }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/css/demo.css')) }}" />


<link rel="stylesheet" href="{{ asset(mix('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css')) }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/libs/typeahead-js/typeahead.css')) }}" />

<!-- Vendor Styles -->
@yield('vendor-style')


<!-- Page Styles -->
@yield('page-style')

<!-- Alladin: global spacing + title overrides -->
<style>
  /* Trim the empty navbar so pages start higher */
  #layout-navbar.layout-navbar {
    min-height: auto;
    padding-top: 0;
    padding-bottom: 0;
  }
  /* Less top padding on page content across all pages */
  .content-wrapper .container-p-y {
    padding-top: 1rem !important;
  }
  /* Consistent page heading */
  .page-title {
    font-weight: 600;
    font-size: 1.35rem;
    margin: 0 0 1rem 0;
  }
  /* Prettier pagination — spaced rounded pills */
  .pagination {
    gap: 0.35rem;
    align-items: center;
    flex-wrap: wrap;
  }
  .pagination .page-item .page-link,
  .pagination .page-item span.page-link {
    min-width: 2.25rem;
    height: 2.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 0.6rem;
    border-radius: 0.5rem;
    border: 1px solid #e4e6ea;
    color: #566a7f;
    font-weight: 500;
    transition: all 0.15s ease-in-out;
  }
  .pagination .page-item .page-link:hover {
    background-color: #f5f5f9;
    border-color: #d9dee3;
    color: #435971;
  }
  .pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #f5486d 0%, #e91e63 100%) !important;
    border-color: #e91e63 !important;
    color: #fff !important;
    box-shadow: 0 0.25rem 0.5rem rgba(233, 30, 99, 0.4) !important;
  }
  .pagination .page-item.disabled .page-link {
    border-color: #eceef1;
    color: #b4bdc6;
    background-color: transparent;
  }
  /* Sidebar user card + logout, pinned to bottom */
  #layout-menu {
    display: flex;
    flex-direction: column;
  }
  #layout-menu .menu-inner {
    flex: 1 1 auto;
  }
  .menu-user {
    border-top: 0;
  }
  .menu-user-avatar {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #f5486d 0%, #e91e63 100%);
    color: #fff;
    font-weight: 600;
    font-size: 0.9rem;
  }
  .menu-user-name {
    font-size: 0.85rem;
    line-height: 1.15;
  }
  .menu-user-email {
    font-size: 0.72rem;
  }
  .menu-logout-btn {
    color: #8592a3;
    border: 0;
    transition: all 0.15s ease-in-out;
  }
  .menu-logout-btn:hover {
    color: #e91e63;
    background-color: rgba(233, 30, 99, 0.1);
  }
  /* Golden brand wordmark (sidebar + login) */
  .app-brand-text {
    background: linear-gradient(135deg, #e8c877 0%, #c9992b 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    color: #c9992b;
  }
</style>

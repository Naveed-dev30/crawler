{{-- @extends('layouts.layoutMaster')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-4">
  <span class="fw-light">Bids</span>
</h4>
<div class=" card">
  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Country</th>
        <th>Currency</th>
        <th>Price</th>
        <th>Status</th>
        <th>Type</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>

      @foreach ($bids as $bid)
        <tr>
          <td>{{ $bid->id }}</td>
          <td>{{ $bid->proposal->country }}</td>
          <td>{{ $bid->proposal->currency_name }} ({{ $bid->proposal->currency_symbol }})</td>
          <td>{{ $bid->price }}$</td>
          <td><span class="badge bg-label-primary me-1">{{ $bid->bid_status }}</span></td>
          <td>{{ $bid->proposal->type }}</td>
          <td>
            <div class="dropdown">
              <button type="button" class="btn p-0 dropdown-toggle hide-arrow" data-bs-toggle="dropdown"><i class="bx bx-dots-vertical-rounded"></i></button>
              <div class="dropdown-menu">
                <a class="dropdown-item" href="{{ route('bids.edit', ['bid' => $bid->id]) }}">
                  <i class="bx bx-edit-alt me-1"></i>Edit
              </a>
                <a class="dropdown-item" href="javascript:void(0);"><i class="bx bx-trash me-1"></i>Delete</a>
              </div>
            </div>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endsection --}}


@extends('layouts/layoutMaster')

@section('title', 'DataTables - Tables')

@section('vendor-style')
<link rel="stylesheet" href="{{asset('assets/vendor/libs/datatables-bs5/datatables.bootstrap5.css')}}">
<link rel="stylesheet" href="{{asset('assets/vendor/libs/datatables-responsive-bs5/responsive.bootstrap5.css')}}">
<link rel="stylesheet" href="{{asset('assets/vendor/libs/datatables-select-bs5/select.bootstrap5.css')}}">
<link rel="stylesheet" href="{{asset('assets/vendor/libs/datatables-checkboxes-jquery/datatables.checkboxes.css')}}">
<link rel="stylesheet" href="{{asset('assets/vendor/libs/datatables-fixedcolumns-bs5/fixedcolumns.bootstrap5.css')}}">
<link rel="stylesheet" href="{{asset('assets/vendor/libs/datatables-fixedheader-bs5/fixedheader.bootstrap5.css')}}">
@endsection

@section('vendor-script')
<script src="{{asset('assets/vendor/libs/datatables-bs5/datatables-bootstrap5.js')}}"></script>
@endsection

@section('page-script')
<script src="{{asset('assets/js/tables-datatables-extensions.js')}}"></script>
@endsection

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-4">
  <span class="text-muted fw-light">DataTables /</span> Extensions
</h4>

<!-- Scrollable -->
<div class="card">
  <h5 class="card-header">Scrollable Table</h5>
  <div class="card-datatable text-nowrap">
    <table class="dt-scrollableTable table table-bordered">
      <thead>
        <tr>
          <th>Name</th>
          <th>Position</th>
          <th>Email</th>
          <th>City</th>
          <th>Date</th>
          <th>Salary</th>
          <th>Age</th>
          <th>Experience</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
    </table>
  </div>
</div>
<!--/ Scrollable -->

<hr class="my-5">

<!-- Fixed Header -->
<div class="card">
  <h5 class="card-header">Fixed Header</h5>
  <div class="card-datatable table-responsive">
    <table class="dt-fixedheader table table-bordered">
      <thead>
        <tr>
          <th></th>
          <th></th>
          <th>id</th>
          <th>Name</th>
          <th>Email</th>
          <th>Date</th>
          <th>Salary</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tfoot>
        <tr>
          <th></th>
          <th></th>
          <th>id</th>
          <th>Name</th>
          <th>Email</th>
          <th>Date</th>
          <th>Salary</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<!--/ Fixed Header -->

<hr class="my-5">

<!-- Fixed Columns -->
<div class="card">
  <h5 class="card-header">Fixed Columns</h5>
  <div class="card-datatable text-nowrap">
    <table class="dt-fixedcolumns table table-bordered">
      <thead>
        <tr>
          <th>Name</th>
          <th>Position</th>
          <th>Email</th>
          <th>City</th>
          <th>Date</th>
          <th>Salary</th>
          <th>Age</th>
          <th>Experience</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
    </table>
  </div>
</div>
<!--/ Fixed Columns -->

<hr class="my-5">

<!-- Select -->
<div class="card">
  <h5 class="card-header">Select</h5>
  <div class="card-datatable dataTable_select text-nowrap table-responsive">
    <table class="dt-select-table table table-bordered">
      <thead>
        <tr>
          <th></th>
          <th>Name</th>
          <th>Position</th>
          <th>Email</th>
          <th>City</th>
          <th>Date</th>
          <th>Salary</th>
          <th>Status</th>
        </tr>
      </thead>
    </table>
  </div>
</div>
<!--/ Select -->
@endsection

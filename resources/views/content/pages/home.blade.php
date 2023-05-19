@extends('layouts.layoutMaster')

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
        <th>Open Project</th>
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
            <a class="dropdown-item" href= "https://www.freelancer.com/projects/{{ $bid->proposal->project_id }}">
              <i class="bx  me-1"></i> {{$bid->proposal->project_id}}
          </td>
          <td>
            <a class="dropdown-item" href="{{ route('bids.show', ['bid' => $bid->id]) }}">
              <i class="bx bx-edit-alt me-1"></i>View
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endsection

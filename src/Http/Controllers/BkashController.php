<?php

namespace AtiqurSafayat\Bkash\Http\Controllers;

use AtiqurSafayat\Bkash\Payment\Bkash;
use Illuminate\Routing\Controller;

class BkashController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(protected Bkash $bkashPayment) {}

    /**
     * Handle bkash payment callback
     *
     * @return \Illuminate\Http\Response
     */
    public function callback()
    {
        return $this->bkashPayment->handleCallback(request());
    }
}

<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use DTApi\Services\BookingService;
use Illuminate\Http\Request;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    public function __construct(public BookingService $bookingService)
    {
    }

    public function index(Request $request)
    {
        return $this->bookingService->getBookingByUserType($request);
    }

    public function show(int $id): response
    {
        $job = $this->bookingService->findBooking($id,'translatorJobRel.user');

        return response($job);
    }

    public function store(Request $request): response
    {
        $response = $this->bookingService->createOrUpdate($request->__authenticatedUser, $request->all());

        return response($response);

    }

    public function update(Job $job, Request $request): response
    {
        $response = $this->bookingService->createOrUpdate($request->__authenticatedUser, $request->except(['_token','submit']), $job);

        return response($response);
    }


    public function immediateJobEmail(Request $request)
    {
        $data = $request->all();
 
        $response = $this->bookingService->storeJobEmail($data);

        return response($response);
    }

    public function getHistory(Request $request)
    {
        if($user_id = $request->get('user_id')) {

            $response = $this->bookingService->getUsersJobsHistory($user_id, $request);
            return response($response);
        }

        return null;
    }

    public function acceptJob(Request $request)
    {
        $response = $this->bookingService->acceptJob($request->all(), $request->__authenticatedUser);

        return response($response);
    }

    public function acceptJobWithId(Request $request): response
    {
        $response = $this->bookingService->acceptJobWithId($request->get('job_id'), $request->__authenticatedUser);

        return response($response);
    }

    public function cancelJob(Request $request): response
    {
        $response = $this->bookingService->cancelJobAjax($request->all(), $request->__authenticatedUser);

        return response($response);
    }

    public function endJob(Request $request): response
    {
        $response = $this->bookingService->endJob($request->all());

        return response($response);
    }

    public function customerNotCall(Request $request): response
    {
        $response = $this->bookingService->customerNotCall($request->all());

        return response($response);
    }

    public function getPotentialJobs(Request $request)
    {
        $response = $this->bookingService->getPotentialJobs($request->__authenticatedUser);

        return response($response);
    }

    public function distanceFeed(Request $request)
    {
        return $this->bookingService->distanceFeed($request->all);
    }

    public function reopen(Request $request): response
    {
        $response = $this->bookingService->reopen($request->all());

        return response($response);
    }

    public function resendNotifications(Request $request): response
    {
        $response = $this->bookingService->resendNotifications($request->all());
        return response($response);
    }

    public function resendSMSNotifications(Request $request): response
    {
        try {
            $job = $this->bookingService->findJob($request->jobid);
            $this->bookingService->resendNotifications($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

}

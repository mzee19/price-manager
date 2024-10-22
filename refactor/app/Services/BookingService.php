<?php

namespace DTApi\Services;

use DTApi\Repository\BookingRepository;
use DTApi\Repository\UserRepository;

class BookingService
{
    public function __construct(public BookingRepository $bookingRepository,
                                public UserRepository $userRepository,
                                public JobService $jobService)
    {
    }

    private function getUsersJobs($user_id): array
    {
        $cuser = $this->userRepository->find($user_id);
        $usertype = '';
        $emergencyJobs = collect(); // Use Laravel collections for better manipulation
        $normalJobs = collect();
        $jobs = collect(); // Initialize jobs to an empty collection

        if ($cuser && $cuser->is('customer')) {
            // Fetch jobs for customer and eager load related models
            $jobs = $cuser->jobs()
                ->with([
                    'user.userMeta',
                    'user.average',
                    'translatorJobRel.user.average',
                    'language',
                    'feedback'
                ])
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();

            $usertype = 'customer';
        } elseif ($cuser && $cuser->is('translator')) {
            // Fetch jobs for translator and pluck only the 'jobs' field
            $jobs = Job::getTranslatorJobs($cuser->id, 'new')->pluck('jobs')->flatten();
            $usertype = 'translator';
        }

        // Process jobs into emergency and normal categories
        $jobs->each(function ($jobitem) use ($user_id, &$emergencyJobs, &$normalJobs) {
            if ($jobitem->immediate === 'yes') {
                $emergencyJobs->push($jobitem);
            } else {
                $jobitem['usercheck'] = Job::checkParticularJob($user_id, $jobitem);
                $normalJobs->push($jobitem);
            }
        });

        // Sort normal jobs by 'due' date
        $normalJobs = $normalJobs->sortBy('due');

        return [
            'emergencyJobs' => $emergencyJobs->all(),
            'normalJobs'    => $normalJobs->all(),
            'cuser'         => $cuser,
            'usertype'      => $usertype,
        ];
    }


    public function getBookingByUserType(Request $request): Response
    {

        $user_id = $request->get('user_id');
        $response = [
            'error' => 'Unauthorized access or invalid request',
        ];
        if (!empty($user_id)) {
            $response = $this->getUsersJobs($user_id);
        }

        $user = $request->__authenticatedUser;
        if (in_array($user->user_type, [config('app.admin_role_id'), config('app.superadmin_role_id')])) {
            $response = $this->jobService->getAll($request);
        }

        return response()->json($response);
    }

    public function findBooking(int $id, $with = ''): Booking
    {
        return  $this->repository->with($with)->find($id);
    }

    public function createOrUpdate(User $user, $data = [], $job = null): array
    {
        if($job) {
            return $this->jobService->updateJob($job, $data, $user);
        } else {
            return $this->jobService->store($user, $data);
        }
    }

    public function storeJobEmail(array $data): array
    {
        return $this->jobService->storeJobEmail($data);
    }

    public function getUsersJobsHistory(int $userId, Request $request): array
    {
        return $this->jobService->getUsersJobsHistory($userId, $request);
    }

    public function acceptJob(array $data, User $user): array
    {
        return $this->jobService->acceptJob($data, $user);
    }

    public function acceptJobWithId(int $id, User $user): array
    {
        return $this->jobService->acceptJobWithId($id, $user);
    }

    public function cancelJobAjax(int $id, User $user): array
    {
        return $this->jobService->cancelJobAjax($id, $user);
    }

    public function endJob(array $data): array
    {
        return $this->jobService->endJob($data);
    }

    public function customerNotCall(array $data): array
    {
        return $this->jobService->customerNotCall($data);
    }

    public function getPotentialJobs(User $user): array
    {
        return $this->jobService->getPotentialJobs($user);
    }

    public function distanceFeed(array $data): response
    {
        $distance = isset($data['distance']) && $data['distance'] != "" ? $data['distance'] : "";
        $time = isset($data['time']) && $data['time'] != "" ? $data['time'] : "";
        $jobid = isset($data['jobid']) && $data['jobid'] != "" ? $data['jobid'] : "";
        $session = isset($data['session_time']) && $data['session_time'] != "" ? $data['session_time'] : "";
        $manually_handled = isset($data['manually_handled']) && $data['manually_handled'] != "" ? $data['manually_handled'] : "";
        $by_admin = isset($data['by_admin']) && $data['by_admin'] != "" ? "yes" : "no";
        $admincomment = isset($data['admincomment']) && $data['admincomment'] != "" ? $data['admincomment'] : "";


        if ($data['flagged'] == 'true') {
            if($data['admincomment'] == '') return "Please, add comment";
            $flagged = 'yes';
        } else {
            $flagged = 'no';
        }

        if ($time || $distance) {

            Distance::where('job_id', '=', $jobid)->update(array('distance' => $distance, 'time' => $time));
        }

        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {

            Job::where('id', '=', $jobid)->update(array('admin_comments' => $admincomment, 'flagged' => $flagged, 'session_time' => $session, 'manually_handled' => $manually_handled, 'by_admin' => $by_admin));
        }

        return response('Record updated!');
    }

    public function reopen(array $data): array
    {
        return $this->jobService->reopen($data);
    }

    public function resendNotifications(\Illuminate\Database\Eloquent\Model $job): array
    {
        $this->jobService->resendNotifications($job);
        $this->repository->sendSMSNotificationToTranslator($job);

        return $this->jobService->resendNotifications($job);
    }
}

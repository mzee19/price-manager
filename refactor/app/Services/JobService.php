<?php

namespace DTApi\Services;

use DTApi\Helpers\TeHelper;
use DTApi\Repository\BookingRepository;
use DTApi\Repository\JobRepository;
use DTApi\Repository\UserRepository;
use DTApi\Traits\EmailTrait;
use DTApi\Traits\oneSignalTrait;
use DTApi\Traits\smsTrait;

class JobService
{
    use EmailTrait, oneSignalTrait, smsTrait;
    public function __construct(public JobRepository $jobRepository)
    {
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        $allJobs = $this->jobRepository->query();

        if ($cuser && $cuser->user_type == config('app.superadmin_role_id')) {
            $allJobs = $this->applySuperAdminFilters($allJobs, $requestdata);
        } else {
            $allJobs = $this->applyRegularUserFilters($allJobs, $requestdata, $consumer_type);
        }

        if ($limit === 'all') {
            return $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance')->get();
        } else {
            return $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance')->paginate(15);
        }
    }

    public function store($user, $data)
    {
        $immediateTime = 5;
        $consumerType = $user->userMeta->consumer_type;

        // Check if the user is a customer
        if ($user->user_type != config('roles.customer_role_id')) {
            return [
                'status' => 'fail',
                'message' => 'Translator cannot create booking'
            ];
        }

        // Validate required fields
        $requiredFields = [
            'from_language_id' => 'Du måste fylla in alla fält',
            'duration' => 'Du måste fylla in alla fält',
        ];

        foreach ($requiredFields as $field => $message) {
            if (empty($data[$field])) {
                return [
                    'status' => 'fail',
                    'message' => $message,
                    'field_name' => $field
                ];
            }
        }

        // Validate for non-immediate bookings
        if ($data['immediate'] == 'no') {
            $optionalFields = [
                'due_date' => 'due_date',
                'due_time' => 'due_time',
                'customer_phone_type' => 'customer_phone_type',
                'duration' => 'duration',
            ];

            foreach ($optionalFields as $field => $message) {
                if (empty($data[$field])) {
                    return [
                        'status' => 'fail',
                        'message' => "Du måste fylla in alla fält",
                        'field_name' => $field,
                    ];
                }
            }
        }

        // Handle phone and physical type
        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
        $response['customer_physical_type'] = $data['customer_physical_type'];

        // Handle immediate bookings
        if ($data['immediate'] == 'yes') {
            $dueCarbon = Carbon::now()->addMinutes($immediateTime);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            $response['type'] = 'immediate';
        } else {
            $due = "{$data['due_date']} {$data['due_time']}";
            $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            $response['type'] = 'regular';

            if ($dueCarbon->isPast()) {
                return [
                    'status' => 'fail',
                    'message' => "Can't create booking in the past",
                ];
            }
        }

        // Determine job attributes
        $data['gender'] = in_array('male', $data['job_for']) ? 'male' : (in_array('female', $data['job_for']) ? 'female' : null);
        $data['certified'] = $this->getCertifiedType($data['job_for']);
        $data['job_type'] = $this->getJobType($consumerType);

        $data['b_created_at'] = now();
        $data['will_expire_at'] = TeHelper::willExpireAt($data['due'], $data['b_created_at']);
        $data['by_admin'] = $data['by_admin'] ?? 'no';

        // Create the job
        $data['user_id'] = $user->id;
        $job = $this->jobRepository->create($data);
        $response['status'] = 'success';
        $response['id'] = $job->id;

        $data['job_for'] = $this->getJobForData($job);

        $data['customer_town'] = $user->userMeta->city;
        $data['customer_type'] = $user->userMeta->customer_type;

        //Event::fire(new JobWasCreated($job, $data, '*'));

//            $this->sendNotificationToSuitableTranslators($job->id, $data, '*');// send Push for New job posting

        return $response;
    }

    public function updateJob($job, $data, $cuser): array
    {
        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator))
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $log_data = [];

        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        if ($job->due != $data['due']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = [
                'old_due' => $job->due,
                'new_due' => $data['due']
            ];
            $changeDue = true;
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $job->id . '">#' . $job->id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
        }

        return [];
    }

    public function changeTranslator(?Translator $currentTranslator, array $data, $job): array
    {
        $translatorChanged = false;
        $logData = [];

        // Check if translator change is necessary
        if (!is_null($currentTranslator) || $this->translatorChangeRequested($data)) {
            // Handle existing translator change
            if ($this->shouldChangeExistingTranslator($currentTranslator, $data)) {
                $data['translator'] = $this->getTranslatorId($data);

                if ($data['translator']) {
                    $newTranslator = $this->createNewTranslator($currentTranslator, $data, $job);
                    $this->cancelCurrentTranslator($currentTranslator);

                    $logData[] = [
                        'old_translator' => $currentTranslator->user->email,
                        'new_translator' => $newTranslator->user->email,
                    ];

                    $translatorChanged = true;
                }
            }
            // Handle assigning a new translator
            elseif (is_null($currentTranslator) && $this->translatorChangeRequested($data)) {
                $data['translator'] = $this->getTranslatorId($data);

                if ($data['translator']) {
                    $newTranslator = Translator::create([
                        'user_id' => $data['translator'],
                        'job_id' => $job->id,
                    ]);

                    $logData[] = [
                        'old_translator' => null,
                        'new_translator' => $newTranslator->user->email,
                    ];

                    $translatorChanged = true;
                }
            }

            // Return result with logs if translator changed
            if ($translatorChanged) {
                return [
                    'translatorChanged' => $translatorChanged,
                    'new_translator' => $newTranslator,
                    'log_data' => $logData,
                ];
            }
        }

        // Return default result if no change happened
        return ['translatorChanged' => $translatorChanged];
    }

    public function jobToData($job)
    {

        $data = array();
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            switch ($job->certified) {
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':case 'n_law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
            }
        }

        return $data;

    }

    private function changeCompletedStatus($job, $data): bool
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
    }

    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $dataEmail = collect([
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura',
                'subject'      => 'Information om avslutad tolkning för bokningsnummer #' . $job->id,
                'email'        => $email,
                'view'         => 'emails.session-ended',
                'name'         => $user->name,
            ]);

            $this->sendingEmail($dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön',
                'subject'      => 'Information om avslutad tolkning för bokningsnummer # ' . $job->id,
                'email'        => $email,
                'view'         => 'emails.session-ended',
                'name'         => $user->name,
            ];
            $this->sendingEmail($dataEmail);
        }
        $job->save();
        return true;
    }

    private function changePendingStatus($job, $data, $changedTranslator): bool
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = collect([
            'user' => $user,
            'job'  => $job,
            'email'  => $email,
            'name'  => $user->name,
        ]);

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $dataEmail->subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $dataEmail->view = 'emails.job-accepted';
            $this->sendingEmail($dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $dataEmail->subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $dataEmail->view = 'emails.job-changed-translator-new-translator';
            $dataEmail->email = $translator->email;
            $dataEmail->name = $translator->name;
            $this->sendingEmail($dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $dataEmail->subject = 'Avbokning av bokningsnr: #' . $job->id;
            $dataEmail->view = 'emails.status-changed-from-pending-or-assigned-customer';
            $this->sendingEmail($dataEmail);
            $job->save();
            return true;
        }
    }

    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = collect([
                    'user' => $user,
                    'job'  => $job,
                    'email'  => $email,
                    'name'  => $name,
                ]);
                $dataEmail->subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $dataEmail->view = 'emails.status-changed-from-pending-or-assigned-customer';
                $this->sendingEmail($dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $dataEmail->subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail->view = 'emails.job-cancel-translator';
                $dataEmail->email = $user->email;
                $dataEmail->name = $user->name;
                $this->sendingEmail($dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    public function storeJobEmail(array $data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();
        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $user->name;
        } else {
            $email = $user->email;
            $name = $user->name;
        }
        $dataEmail = collect([
            'user' => $user,
            'job'  => $job,
            'view'  => 'emails.job-created',
            'email'  => $email,
            'name'  => $name,
            'subject'  => 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id,
        ]);

        $this->sendingEmail($dataEmail);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;
    }

    public function getUsersJobsHistory($user_id, Request $request): array
    {
        $page = $request->get('page');
        if (isset($page)) {
            $pagenum = $page;
        } else {
            $pagenum = "1";
        }
        $cuser = User::find($user_id);
        $emergencyJobs = array();
        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser->jobs()->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance'])
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => 'customer', 'numpages' => 0, 'pagenum' => 0];
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $jobs = $jobs_ids;
            $noramlJobs = $jobs_ids;
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => 'translator', 'numpages' => $numpages, 'pagenum' => $pagenum];
        }
        return [];
    }

    public function acceptJob($data, $user): array
    {
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = $this->jobRepository->findOrFail($job_id);
        if (!$this->jobRepository->isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && $this->jobRepository->insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $dataEmail = collect([
                    'user' => $job->user()->get()->first(),
                    'job'  => $job,
                    'view'  => 'emails.job-created',
                    'email'  => $job->user_email ?? $user->email,
                    'name'  => $user->name,
                    'subject'  => 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')',
                ]);


                $this->sendingEmail($dataEmail);
            }

            $jobs = $this->getPotentialJobs($cuser);
            $response = array();
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;
    }

    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translator_type = $cuser_meta->translator_type;
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = $this->jobRepository->getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = $this->jobRepository->assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = $this->jobRepository->checkParticularJob($cuser->id, $job);
            $checktown = $this->jobRepository->checkTowns($jobuserid, $cuser->id);

            if($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                    unset($job_ids[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
//        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $job_ids;
    }

    public function acceptJobWithId(int $job_id, $cuser): array
    {
        $job = $this->jobRepository->findOrFail($job_id);
        $response = array();

        if (!$this->jobRepository->isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && $this->jobRepository->insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();

                $dataEmail = collect([
                    'user' => $user,
                    'job'  => $job,
                    'view'  => 'emails.job-created',
                    'email'  => $job->user_email ?? $user->email,
                    'name'  => $user->name,
                    'subject'  => 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')',
                ]);
                $this->sendingEmail($dataEmail);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = array($user);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = array();

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = $this->jobRepository->findOrFail($job_id);
        $translator = $this->jobRepository->getJobsAssignedTranslatorDetail($job);
        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
//                Event::fire(new JobWasCanceled($job));
                $this->jobRepository->deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = $this->jobRepository->with('translatorJobRel')->find($jobid);

        if($job_detail->status != 'started')
            return ['status' => 'success'];

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $dataEmail = collect([
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura',
            'view'  => 'emails.session-ended',
            'email'  => $email,
            'name'  => $name,
            'subject'  => 'Information om avslutad tolkning för bokningsnummer # ' . $job->id,
        ]);
        $this->sendingEmail($dataEmail);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;

        $dataEmail = collect([
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön',
            'view'  => 'emails.session-ended',
            'email'  => $email,
            'name'  => $name,
            'subject'  =>  'Information om avslutad tolkning för bokningsnummer # ' . $job->id,
        ]);
        $this->sendingEmail($dataEmail);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function customerNotCall($post_data): array
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = $this->jobRepository->with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = $this->jobRepository->find($jobid)->toArray();

        $data = array();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userid;
        $data['job_id'] = $jobid;
        $data['cancel_at'] = now();

        $datareopen = array();
        $datareopen['status'] = 'pending';
        $datareopen['created_at'] = now();
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);

        if ($job['status'] != 'timedout') {
            $affectedRows = $this->jobRepository->where('id', $jobid)->update($datareopen);
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = now();
            $job['updated_at'] = now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            $affectedRows = $this->jobRepository->create($job);
        }
        //$result = DB::table('translator_job_rel')->insertGetId($data);
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($affectedRows);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    public function sendNotificationByAdminCancelJob($job): void
    {
        $user_meta = $job->user->userMeta()->first();
        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    public function resendNotifications($job): array
    {
        $job_data = $this->jobToData($job);
        $this->sendNotificationTranslator($job, $job_data, '*');
        return ['success' => 'Push sent'];
    }

    private function applySuperAdminFilters($allJobs, $requestdata)
    {
        // Feedback filter
        if (!empty($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $allJobs->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
            if (!empty($requestdata['count']) && $requestdata['count'] != 'false') {
                return ['count' => $allJobs->count()];
            }
        }

        // Apply all common filters
        $allJobs = $this->applyCommonFilters($allJobs, $requestdata);

        // Consumer type filter
        if (!empty($requestdata['consumer_type'])) {
            $allJobs->whereHas('user.userMeta', function ($q) use ($requestdata) {
                $q->where('consumer_type', $requestdata['consumer_type']);
            });
        }

        return $allJobs;
    }

    private function applyRegularUserFilters($allJobs, $requestdata, $consumer_type)
    {
        $allJobs->where('job_type', $consumer_type == 'RWS' ? 'rws' : 'unpaid');

        $allJobs = $this->applyCommonFilters($allJobs, $requestdata);

        return $allJobs;
    }

    private function applyCommonFilters($allJobs, $requestdata)
    {
        // Job ID filter
        if (!empty($requestdata['id'])) {
            $allJobs->whereIn('id', (array)$requestdata['id']);
        }

        // Language filter
        if (!empty($requestdata['lang'])) {
            $allJobs->whereIn('from_language_id', $requestdata['lang']);
        }

        // Status filter
        if (!empty($requestdata['status'])) {
            $allJobs->whereIn('status', $requestdata['status']);
        }

        // Time-based filters (created_at or due)
        $allJobs = $this->applyTimeFilters($allJobs, $requestdata);

        // Email-based filters (customer/translator)
        $allJobs = $this->applyEmailFilters($allJobs, $requestdata);

        return $allJobs;
    }

    private function applyTimeFilters($allJobs, $requestdata)
    {
        if (!empty($requestdata['filter_timetype'])) {
            $field = $requestdata['filter_timetype'] == 'created' ? 'created_at' : 'due';

            if (!empty($requestdata['from'])) {
                $allJobs->where($field, '>=', $requestdata['from']);
            }

            if (!empty($requestdata['to'])) {
                $allJobs->where($field, '<=', $requestdata['to'] . ' 23:59:00');
            }

            $allJobs->orderBy($field, 'desc');
        }

        return $allJobs;
    }

    private function applyEmailFilters($allJobs, $requestdata)
    {
        if (!empty($requestdata['customer_email'])) {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $allJobs->where('user_id', $user->id);
            }
        }

        if (!empty($requestdata['translator_email'])) {
            $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
            if ($users->isNotEmpty()) {
                $allJobIDs = DB::table('translator_job_rel')
                    ->whereNull('cancel_at')
                    ->whereIn('user_id', $users->pluck('id'))
                    ->pluck('job_id');
                $allJobs->whereIn('id', $allJobIDs);
            }
        }

        return $allJobs;
    }

    private function getCertifiedType(array $jobFor): ?string
    {
        if (in_array('normal', $jobFor) && in_array('certified', $jobFor)) {
            return 'both';
        } elseif (in_array('normal', $jobFor) && in_array('certified_in_law', $jobFor)) {
            return 'n_law';
        } elseif (in_array('normal', $jobFor) && in_array('certified_in_helth', $jobFor)) {
            return 'n_health';
        } elseif (in_array('certified', $jobFor)) {
            return 'yes';
        } elseif (in_array('certified_in_law', $jobFor)) {
            return 'law';
        } elseif (in_array('certified_in_helth', $jobFor)) {
            return 'health';
        }

        return 'normal';
    }

    private function getJobType(string $consumerType): string
    {
        return match ($consumerType) {
            'rwsconsumer' => 'rws',
            'ngo' => 'unpaid',
            default => 'paid',
        };
    }

    private function getJobForData($job): array
    {
        $jobFor = [];

        if ($job->gender === 'male') {
            $jobFor[] = 'Man';
        } elseif ($job->gender === 'female') {
            $jobFor[] = 'Kvinna';
        }

        switch($job->certified) {
            case 'both':
                $jobFor[] = 'normal';
                $jobFor[] = 'certified';
                break;
            case 'yes':
                $jobFor[] = 'certified';
                break;
            default:
                $jobFor[] = $job->certified;
        }

        return $jobFor;
    }

    private function translatorChangeRequested(array $data): bool
    {
        return (isset($data['translator']) && $data['translator'] != 0) || !empty($data['translator_email']);
    }

    private function shouldChangeExistingTranslator(?Translator $currentTranslator, array $data): bool
    {
        return !is_null($currentTranslator) && (
                (isset($data['translator']) && $currentTranslator->user_id != $data['translator']) ||
                !empty($data['translator_email'])
            );
    }

    private function getTranslatorId(array $data): ?int
    {
        if (!empty($data['translator_email'])) {
            $translator = User::where('email', $data['translator_email'])->first();

            if ($translator) {
                return $translator->id;
            }

            // Log or handle the case where translator is not found
            Log::error('Translator email not found: ' . $data['translator_email']);
            return null;
        }

        return $data['translator'] ?? null;
    }

    private function createNewTranslator(Translator $currentTranslator, array $data, $job): Translator
    {
        $newTranslatorData = $currentTranslator->toArray();
        $newTranslatorData['user_id'] = $data['translator'];
        unset($newTranslatorData['id']); // Remove the ID to create a new record

        return Translator::create($newTranslatorData);
    }

    private function cancelCurrentTranslator(Translator $currentTranslator): void
    {
        $currentTranslator->cancel_at = Carbon::now();
        $currentTranslator->save();
    }

    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $emailData = collect([
            'email' => $email,
            'name' => $name,
            'user' => $user,
            'job'  => $job
        ]);
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);
            $emailData->view = 'emails.job-change-status-to-customer';
            $emailData->subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->sendingEmail($emailData);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $emailData->subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $emailData->view = 'emails.job-accepted';
            $this->sendingEmail($emailData);
            return true;
        }

        return false;
    }
}

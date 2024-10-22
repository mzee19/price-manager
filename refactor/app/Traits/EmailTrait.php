<?php

namespace DTApi\Traits;

use App\Jobs\SendEmailJob;

trait EmailTrait
{
    public function sendingEmail(object $data): void
    {
       dispatch(new SendEmailJob($data));
    }

    public function sendChangedDateNotification($job, $old_time): void
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $data = collect([
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time,
            'email' => $email,
            'name' => $name,
            'view' => 'emails.job-changed-date',
            'subject' => 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '',
        ]);
        $this->sendingEmail($data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $data->user = $translator;
        $data->email = $translator->email;
        $data->name = $translator->name;
        $data->view = 'emails.job-changed-date';
        $this->sendingEmail($data);
    }

    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator): void
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $data = colelct([
            'user' => $user,
            'job'  => $job,
            'name'  => $name,
            'email'  => $email,
            'view'  => 'emails.job-changed-translator-customer',
            'subject'  => 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')',
        ]);
        $this->sendingEmail($data);

        if ($current_translator) {
            $data->user = $current_translator;
            $data->email = $current_translator->email;
            $data->name = $current_translator->name;
            $data->view = 'emails.job-changed-translator-old-translator';
            $this->sendingEmail($data);
        }

        $data->user = $new_translator;
        $data->email = $new_translator->user->email;
        $data->name = $new_translator->user->name;
        $data->view = 'emails.job-changed-translator-new-translator';
        $this->sendingEmail($data);
    }

    public function sendChangedLangNotification($job, $old_lang): void
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $data = collect([
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang,
            'email' => $email,
            'name' => $name,
            'view' => 'emails.job-changed-lang',
            'subject' => 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '',
        ]);
        $this->sendingEmail($data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $data->name = $translator->name;
        $data->email = $translator->email;
        $this->sendingEmail($data);
    }
}

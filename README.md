create a example job:
```php
<?php

namespace App\Jobs;

use Phaseolies\Support\Facades\Log;
use Doppar\Queue\Job;

class SendEmailJob extends Job
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     *
     * @var int
     */
    public $retryAfter = 60;

    /**
     * The email data.
     *
     * @var array
     */
    protected $emailData;

    /**
     * Create a new job instance.
     *
     * @param array $emailData
     */
    public function __construct(array $emailData)
    {
        $this->emailData = $emailData;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        // Your email sending logic here
        $to = $this->emailData['to'];
        $subject = $this->emailData['subject'];
        $message = $this->emailData['message'];

        Log::info("Email sent successfully to {$to}");
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        // Send notification to admin about failed email
        Log::error("Failed to send email after {$this->tries} attempts", [
            'email_to' => $this->emailData['to'],
            'exception' => $exception->getMessage(),
        ]);

        // You could send a notification to admins here
    }
}
```

example controller
```
<?php

namespace App\Http\Controllers;

use Phaseolies\Utilities\Attributes\Route;
use Doppar\Queue\Facades\Queue;
use App\Jobs\SendEmailJob;
use App\Http\Controllers\Controller;

class QueueController extends Controller
{
    #[Route('queue')]
    public function queue()
    {
        $job = new SendEmailJob([
            'to' => 'user@example.com',
            'subject' => 'Welcome!',
            'message' => 'Thanks for signing up!',
        ]);

        $jobId = Queue::push($job);

        echo "Job dispatched with ID: {$jobId}\n";

        // with specific name
        $job = new SendEmailJob([
            'to' => 'user@example.com',
            'subject' => 'Welcome!',
            'message' => 'Thanks for signing up!',
        ])->onQueue('emails')
            ->delayFor(10);

        // command will be [php pool queue:run --queue=emails]

        $jobId = Queue::push($job);
    }
}
```

then add provider in your `config/app.php`
```php
 \Doppar\Queue\QueueServiceProvider::class
```

Run
```php
php pool vendor:publish --provider="Doppar\Queue\QueueServiceProvider"
// now migrate
php pool migrate
```

Then run
```bash
php pool queue:run

# Specify only the queue name
php pool queue:run --queue=emails
php pool queue:run --queue=notifications
php pool queue:run --queue=reports

# Change sleep time between jobs
php pool queue:run --sleep=1
php pool queue:run --sleep=5
php pool queue:run --queue=emails --sleep=10

# Adjust memory limit
php pool queue:run --memory=256
php pool queue:run --memory=512 --queue=emails
php pool queue:run --memory=1024 --sleep=2

# Adjust timeout (max execution time in seconds)
php pool queue:run --timeout=600
php pool queue:run --timeout=1800 --queue=emails
php pool queue:run --timeout=7200 --memory=512

# Combine all options (custom configuration)
php pool queue:run --queue=emails --sleep=5 --memory=256 --timeout=900
php pool queue:run --queue=notifications --sleep=3 --memory=512 --timeout=1800
php pool queue:run --queue=reports --sleep=10 --memory=1024 --timeout=3600
```


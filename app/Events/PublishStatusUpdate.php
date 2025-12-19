<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PublishStatusUpdate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $jobIdent;
    public $isPublishing;
    public $status;

    /**
     * Create a new event instance.
     *
     * @param string $jobIdent Job identifier (e.g., J-100001)
     * @param bool $isPublishing Whether job is currently publishing
     * @param string $status Status message (e.g., 'started', 'completed', 'failed')
     */
    public function __construct($jobIdent, $isPublishing, $status = null)
    {
        $this->jobIdent = $jobIdent;
        $this->isPublishing = $isPublishing;
        $this->status = $status;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('jobs'), // Public channel for all jobs
        ];
    }
}

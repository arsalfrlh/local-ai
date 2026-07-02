<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AiChatEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    protected $isDone;
    protected $content;
    protected $think;

    /**
     * Create a new event instance.
     */
    public function __construct($content, $think, $isDone)
    {
        $this->content = $content;
        $this->think = $think;
        $this->isDone = $isDone;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('ai-response-room'),
        ];
    }

    public function broadcastAs(){
        return "responseAi";
    }

    public function broadcastWith(){
        return [
            'content' => $this->content,
            'think' => $this->think,
            'is_done' => $this->isDone
        ];
    }
}

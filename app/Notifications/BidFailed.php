<?php

namespace App\Notifications;

use App\Models\Bid;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

class BidFailed extends Notification
{
    use Queueable;

    protected $bid;

    /**
     * Create a new notification instance.
     */
    public function __construct(Bid $bid)
    {
        $this->bid = $bid;
    }


    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text(':sad: one of your bid failed just now.')
            ->headerBlock('Bid Failed')
            ->contextBlock(function (ContextBlock $block) {
                $block->text('Bid #' . $this->bid->id);
            })
            ->sectionBlock(function (SectionBlock $block) {
                $block->text('Following bid got failed');
                $block->field("*Bid no:*\n" . $this->bid->id)->markdown();
                $block->field("*Error message:*\n" . $this->bid->error_message)->markdown();
//                $block->field("*Our proposed price:*\n".$this->bid->price)->markdown();
//                $block->field("*Project Type:*\n".$this->bid->proposal->type)->markdown();
//                $block->field("*Project Min Budget:*\n".$this->bid->proposal->min_budget)->markdown();
//                $block->field("*Project Max Budget:*\n".$this->bid->proposal->max_budget)->markdown();
            })
            ->dividerBlock();
//            ->sectionBlock(function (SectionBlock $block){
//                $block->field("*Project Description:*\n".$this->bid->proposal->description)->markdown();
//            })
//            ->dividerBlock()
//            ->sectionBlock(function (SectionBlock $block){
//                $block->field("*Our Proposal:*\n".$this->bid->cover_letter)->markdown();
//            });
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}

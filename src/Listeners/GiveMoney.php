<?php

namespace AntoineFr\Money\Listeners;

use Illuminate\Support\Arr;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Flarum\User\User;
use Flarum\Post\Post;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored as PostRestored;
use Flarum\Post\Event\Hidden as PostHidden;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Started;
use Flarum\Discussion\Event\Restored as DiscussionRestored;
use Flarum\Discussion\Event\Hidden as DiscussionHidden;
use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\User\Event\Saving;
use Flarum\Likes\Event\PostWasLiked;
use Flarum\Likes\Event\PostWasUnliked;
use AntoineFr\Money\Event\MoneyUpdated;
use AntoineFr\Money\AutoRemoveEnum;

class GiveMoney
{
    protected SettingsRepositoryInterface $settings;
    protected Dispatcher $events;
    protected float $moneyforpost;
    protected int $postminimumlength;
    protected float $moneyfordiscussion;
    protected float $moneyforlike;
    protected int $autoremove;
    protected bool $cascaderemove;
    protected bool $ignoreNotifyingUsersSwitch;

    public function __construct(SettingsRepositoryInterface $settings, Dispatcher $events)
    {
        $this->settings = $settings;
        $this->events = $events;

        $this->moneyforpost = (float) $this->settings->get('antoinefr-money.moneyforpost', 0);
        $this->postminimumlength = (int) $this->settings->get('antoinefr-money.postminimumlength', 0);
        $this->moneyfordiscussion = (float) $this->settings->get('antoinefr-money.moneyfordiscussion', 0);
        $this->moneyforlike = (float) $this->settings->get('antoinefr-money.moneyforlike', 0);
        $this->autoremove = (int) $this->settings->get('antoinefr-money.autoremove', 1);
        $this->cascaderemove = (bool) $this->settings->get('antoinefr-money.cascaderemove', false);
        $this->ignoreNotifyingUsersSwitch = (bool) $this->settings->get('antoinefr-money.ignorenotifyingusers', false);
    }

    /**
     * 【核心功能】检查用户是否可以在当前讨论中获得积分
     * 该逻辑已取消管理员和版主豁免，实现真正的全站统一。
     */
    protected function canUserEarnMoney(?User $user, ?Discussion $discussion): bool
    {
        if (is_null($user) || is_null($discussion)) return false;

        // 遍历当前讨论帖的所有标签
        foreach ($discussion->tags as $tag) {
            // 检查该用户在当前标签下是否拥有“禁用积分”权限
            // 在 Flarum 中，管理员默认拥有所有权限，因此也会在此被精准拦截
            if ($user->hasPermission("tag{$tag->id}.discussion.money.disable_money")) {
                return false; // 只要命中一个禁分标签，直接返回“不可获得积分”
            }
        }

        return true; // 没有任何禁分标签时，允许加分
    }

    public function giveMoney(?User $user, float $money): bool
    {
        if (!is_null($user)) {
            $user->money += $money;
            $user->save();
            $this->events->dispatch(new MoneyUpdated($user));
            return true;
        }
        return false;
    }

    public function postGiveMoney(?User $user, float $money, Post $post)
    {
        // 增加权限检查逻辑
        if ($this->canUserEarnMoney($user, $post->discussion)) {
            $this->giveMoney($user, $money);
        }
    }

    public function ignoreNotifyingUsers(string $content): string
    {
        if (!$this->ignoreNotifyingUsersSwitch) return $content;
        $pattern = '/@.*(#\d+|#p\d+)/';
        return trim(str_replace(["\r", "\n"], '', preg_replace($pattern, '', $content)));
    }

    public function postWasPosted(Posted $event): void
    {
        $content = $this->ignoreNotifyingUsers($event->post->content);
        if ($event->post->number > 1 && mb_strlen($content) >= $this->postminimumlength) {
            $this->postGiveMoney($event->actor, $this->moneyforpost, $event->post);
        }
    }

    public function discussionWasStarted(Started $event): void
    {
        // 修正：新主题发帖逻辑现在也会检查禁分权限
        if ($this->canUserEarnMoney($event->actor, $event->discussion)) {
            $this->giveMoney($event->actor, $this->moneyfordiscussion);
        }
    }

    public function postWasRestored(PostRestored $event): void
    {
        $content = $this->ignoreNotifyingUsers($event->post->content);
        if ($this->autoremove == AutoRemoveEnum::HIDDEN && $event->post->type == 'comment' && mb_strlen($content) >= $this->postminimumlength) {
            $this->postGiveMoney($event->post->user, $this->moneyforpost, $event->post);
        }
    }

    public function postWasHidden(PostHidden $event): void
    {
        $content = $this->ignoreNotifyingUsers($event->post->content);
        if ($this->autoremove == AutoRemoveEnum::HIDDEN && $event->post->type == 'comment' && mb_strlen($content) >= $this->postminimumlength) {
            $this->postGiveMoney($event->post->user, -1 * $this->moneyforpost, $event->post);
        }
    }

    public function postWasDeleted(PostDeleted $event): void
    {
        $content = $this->ignoreNotifyingUsers($event->post->content);
        if ($this->autoremove == AutoRemoveEnum::DELETED && $event->post->type == 'comment' && mb_strlen($content) >= $this->postminimumlength) {
            $this->postGiveMoney($event->post->user, -1 * $this->moneyforpost, $event->post);
        }
    }

    public function discussionWasRestored(DiscussionRestored $event): void
    {
        if ($this->autoremove == AutoRemoveEnum::HIDDEN) {
            if ($this->canUserEarnMoney($event->discussion->user, $event->discussion)) {
                $this->giveMoney($event->discussion->user, $this->moneyfordiscussion);
            }
            $this->discussionCascadePosts($event->discussion, 1);
        }
    }

    public function discussionWasHidden(DiscussionHidden $event): void
    {
        if ($this->autoremove == AutoRemoveEnum::HIDDEN) {
            $this->giveMoney($event->discussion->user, -$this->moneyfordiscussion);
            $this->discussionCascadePosts($event->discussion, -1);
        }
    }

    public function discussionWasDeleted(DiscussionDeleted $event): void
    {
        if ($this->autoremove == AutoRemoveEnum::DELETED) {
            $this->giveMoney($event->discussion->user, -$this->moneyfordiscussion);
            $this->discussionCascadePosts($event->discussion, -1);
        }
    }

    protected function discussionCascadePosts(Discussion $discussion, int $multiply): void
    {
        if ($this->cascaderemove) {
            foreach ($discussion->posts as $post) {
                $content = $this->ignoreNotifyingUsers($post->content);
                if ($post->type == 'comment' && mb_strlen($content) >= $this->postminimumlength && $post->number > 1 && is_null($post->hidden_at)) {
                    $this->postGiveMoney($post->user, $multiply * $this->moneyforpost, $post);
                }
            }
        }
    }

    public function userWillBeSaved(Saving $event): void
    {
        $attributes = Arr::get($event->data, 'attributes', []);
        if (array_key_exists('money', $attributes)) {
            $user = $event->user;
            $actor = $event->actor;
            $actor->assertCan('edit_money', $user);
            $user->money = (float) $attributes['money'];
            $this->events->dispatch(new MoneyUpdated($user));
        }
    }

    public function postWasLiked(PostWasLiked $event): void
    {
        // 喜欢功能目前未挂载标签判断，如需点赞也不给钱，可在此增加 $this->canUserEarnMoney 判断
        $this->giveMoney($event->post->user, $this->moneyforlike);
    }

    public function postWasUnliked(PostWasUnliked $event): void
    {
        $this->giveMoney($event->post->user, -1 * $this->moneyforlike);
    }
}

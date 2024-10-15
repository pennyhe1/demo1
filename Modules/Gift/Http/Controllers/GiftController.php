<?php

namespace Modules\Gift\Http\Controllers;

use App\Http\Constants\ApiStatus;
use App\Http\Helpers\RedisHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Gift\Http\Repositories\GiftRepository;
use Modules\Gift\Models\Gift;
use Modules\Gift\Models\User;
use Throwable;

class GiftController extends Controller
{
    /**
     * 赠送幸运礼物(按组赠送礼物)
     * User:ytx
     * DateTime:2023/2/24 15:33
     */
    public function giveGroupGift(Request $request): JsonResponse
    {

        $uid          = $request->uid;
        $to_uid       = $request->input('to_uid', ''); # '1,2,3' 多用户(全麦)
        $to_uid       = array_filter(explode(',', $to_uid));
        $gift_id      = $request->input('gift_id', 0);
        $scene        = $request->input('scene', ''); # room|black|chat|help|bar_greet|bar_dating
        $scene_id     = (int)$request->input('scene_id', 0); # 房间id|小黑屋id,聊天会话id
        $number_group = (int)$request->input('number_group', 1);# 礼物组数，默认最小组数1组

        if (in_array($uid, $to_uid)) {
            return responseError([0, '不能送给自己']);
        }

        //防止高并发 礼物信息加入redis缓存 数据存在取redis 不存在取数据库在存redis
        $giftkey       = 'luckgift' . $gift_id;
        $GiveGiftRedis = RedisHelper::get($giftkey);
        if ($GiveGiftRedis) {
            $giftInfo = json_decode($GiveGiftRedis, true);
        } else {
            $giftInfo = Gift::query()->where(['id' => $gift_id, 'status' => 1])->with('unit_price')->first();
            if (empty($giftInfo)) {
                return responseError([0, '所选礼物不存在，无法赠送']);
            }
            RedisHelper::set($giftkey, json_encode($giftInfo));
        }

        # 礼物接收者人数
        $to_uid_num = count($to_uid);

        # 礼物单价（单价*倍数=返现的币数）
        $giftUnitCoin = $giftInfo['unit_price']['coin'];

        # 礼物所需总价值
        $giftTotalNeedCoin = bcmul($giftInfo['coin'], $number_group * $to_uid_num, 8);

        # 每个人收到的礼物价值
        $eachUserGiftCoin = bcmul($giftInfo['coin'], $number_group, 8);

        # 校验用户币余额是否充足
        $userCoinBalance = User::query()->where(['id' => $uid])->value('coin');
        if ($userCoinBalance < $giftTotalNeedCoin) {
            return responseError(ApiStatus::PLATFORM_COIN_INSUFFICIENT);
        }

        try {
            User::startTrans();
            // 前置验证：检查送礼的条件是否符合规则。
            if(...){
                User::rollback();
                return responseError([0, '所选礼物规则不符合，无法赠送']);
            }
            // 扣除金币：从A用户的账户扣除相应的金币，并记录礼物的详细信息。
            $aCoinBalance = User::execute()->where(['id' => $uid])->value('coin' - $giftUnitCoin);
            //$bCoinBalance = User::execute()->where(['id' => $to_uid])->value('coin'+$giftUnitCoin);
            // 礼物墙更新：在B用户的礼物墙上记录收到的礼物。

            $giftWall = User::execute("insert into wall(...) values ({$to_uid},{$gift_id})");

            // 福利概率：A用户赠送的礼物有一定概率触发N倍增幅收益，将增幅后的礼物收益分配给B用户。
            $multiple = intval(rand(0,1) * 2);
            $bCoinBalance = User::execute()->where(['id' => $to_uid])->value('coin' + $giftUnitCoin * $multiple);

            // 更新房间排名：礼物赠送会更新房间的流水排名，用于首页推荐。
            $room = User::execute("update room set 流水=流水+{$giftUnitCoin} where scene_id={$scene_id}");

            // 用户排名更新：更新A用户在房间的消费情况，用于房间内用户排名。
            //不清楚用什么方法，先用redis（已加载redis为$redis）:
            $aUser = $redis->hgetall($uid);
            $redis->set($uid, {A的流水}+{$giftUnitCoin});

            // 房间消费统计：更新房间的总消费币数和金额，用于与官方的对账。
            User::execute("insert into 统计表(...) values ({$scene_id},{$giftUnitCoin}等数据)");

            // 收益分配：记录礼物收益的分配，包括房主、主持、以及礼物接收者的分成。            
            User::execute("insert into 分成表(...) values (房主,{$giftUnitCoin}*0.2)");
            User::execute("insert into 分成表(...) values (主持,{$giftUnitCoin}*0.1)");
            User::execute("insert into 分成表(...) values (接收者,{$giftUnitCoin}*0.7)");

            // 魅力值增加：赠送礼物的A用户在房间内的魅力值会增加。
            User::execute("update user set 魅力值+{$giftUnitCoin}");

            // 用户等级增加：A用户的等级会因为送礼行为而提升。
            User::execute("update user set 等级+{$giftUnitCoin}*0.1");

            // 通知机制：发送通知给房间内，告知其已成功赠送礼物给B用户。
            User::execute("insert into 通知表(...) values ({$scene_id},{$giftUnitCoin})");

            // 飘屏机制：当N倍增幅数值达到较高倍数时，系统会进行全服飘屏展示。
            if($multiple>99){
                User::execute("insert into 飘屏表(...) values ({$scene_id},{$giftUnitCoin},{$multiple})");                
            }
            foreach ($to_uid as $v) {
                # 赠送礼物
                GiftRepository::Factory()->doGiveGroupGift($uid, $v, $giftInfo, $scene, $scene_id, $number_group, $eachUserGiftCoin, $giftUnitCoin);
            }


            return responseSuccess();
        } catch (Throwable $e) {
            dp('礼物赠送出错,结束任务');
            return responseError([0, $e->getMessage()]);
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-room thresholds and options. Read through settings() on the room;
 * defaults live in config/mines.php ('room_defaults').
 *
 * @property int $control_room_id
 * @property string $key
 * @property mixed $value
 */
class ControlRoomSetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['value' => 'array'];

    public static function for(ControlRoom $room): RoomSettings
    {
        return new RoomSettings($room);
    }
}

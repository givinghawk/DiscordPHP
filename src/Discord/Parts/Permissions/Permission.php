<?php

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-present David Cole <david.cole1340@gmail.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Parts\Permissions;

use Discord\Discord;
use Discord\Helpers\Bitwise;
use Discord\Parts\Part;

/**
 * Permission represents a set of permissions for a given role or overwrite.
 *
 * @property int|string $bitwise
 * @property bool       $create_instant_invite
 * @property bool       $manage_channels
 * @property bool       $view_channel
 * @property bool       $manage_roles
 * @property bool       $manage_webhooks
 */
abstract class Permission extends Part
{
    /**
     * Array of permissions that only apply to voice channels.
     *
     * @var array
     */
    public const VOICE_PERMISSIONS = [
        'priority_speaker' => 8,
        'stream' => 9,
        'connect' => 20,
        'speak' => 21,
        'mute_members' => 22,
        'deafen_members' => 23,
        'move_members' => 24,
        'use_vad' => 25,
        'request_to_speak' => 32,
        'manage_events' => 33,
        'start_embedded_activities' => 39,
    ];

    /**
     * Array of permissions that only apply to text channels.
     *
     * @var array
     */
    public const TEXT_PERMISSIONS = [
        'add_reactions' => 6,
        'send_messages' => 11,
        'send_tts_messages' => 12,
        'manage_messages' => 13,
        'embed_links' => 14,
        'attach_files' => 15,
        'read_message_history' => 16,
        'mention_everyone' => 17,
        'use_external_emojis' => 18,
        'use_application_commands' => 31,
        'manage_threads' => 34,
        'create_public_threads' => 35,
        'create_private_threads' => 36,
        'use_external_stickers' => 37,
        'send_messages_in_threads' => 38,
    ];

    /**
     * Array of permissions that can only be applied to roles.
     *
     * @var array
     */
    public const ROLE_PERMISSIONS = [
        'kick_members' => 1,
        'ban_members' => 2,
        'administrator' => 3,
        'manage_guild' => 5,
        'view_audit_log' => 7,
        'view_guild_insights' => 19,
        'change_nickname' => 26,
        'manage_nicknames' => 27,
        'manage_emojis_and_stickers' => 30,
        'manage_events' => 33,
        'moderate_members' => 40,
    ];

    /**
     * Array of permissions for all roles.
     *
     * @var array
     */
    public const ALL_PERMISSIONS = [
        'create_instant_invite' => 0,
        'manage_channels' => 4,
        'view_channel' => 10,
        'manage_roles' => 28,
        'manage_webhooks' => 29,
    ];

    /**
     * Array of permissions.
     *
     * @var array
     */
    private $permissions = [];

    /**
     * @inheritdoc
     */
    public function __construct(Discord $discord, array $attributes = [], bool $created = false)
    {
        $this->permissions = $this->getPermissions();
        $this->fillable = array_keys($this->permissions);
        $this->fillable[] = 'bitwise';

        parent::__construct($discord, $attributes, $created);

        foreach ($this->fillable as $permission) {
            if (! isset($this->attributes[$permission])) {
                $this->attributes[$permission] = false;
            }
        }
    }

    /**
     * Returns an array of extra permissions.
     *
     * @return array
     */
    abstract public static function getPermissions(): array;

    /**
     * Gets the bitwise attribute of the permission.
     *
     * @return int|string
     */
    protected function getBitwiseAttribute()
    {
        $bitwise = 0;

        if (Bitwise::$is_32_gmp) { // x86
            $bitwise = \gmp_init(0);

            foreach ($this->permissions as $permission => $value) {
                \gmp_setbit($bitwise, $value, $this->attributes[$permission]);
            }

            return \gmp_strval($bitwise);
        }

        foreach ($this->permissions as $permission => $value) {
            if ($this->attributes[$permission]) {
                $bitwise |= 1 << $value;
            }
        }

        return $bitwise;
    }

    /**
     * Sets the bitwise attribute of the permission.
     *
     * @param int|string $bitwise
     */
    protected function setBitwiseAttribute($bitwise)
    {
        if (PHP_INT_SIZE === 8 && is_string($bitwise)) { // x64
            $bitwise = (int) $bitwise;
        }

        foreach ($this->permissions as $permission => $value) {
            if (Bitwise::test($bitwise, $value)) {
                $this->attributes[$permission] = true;
            } else {
                $this->attributes[$permission] = false;
            }
        }
    }

    public function getUseSlashCommandsAttribute()
    {
        return $this->attributes['use_application_commands'] ?? null;
    }

    public function getUsePublicThreadsAttribute()
    {
        return $this->attributes['create_public_threads'] ?? null;
    }

    public function getUsePrivateThreadsAttribute()
    {
        return $this->attributes['create_private_threads'] ?? null;
    }

    public function getManageEmojisAttribute()
    {
        return $this->attributes['manage_emojis_and_stickers'] ?? null;
    }
}

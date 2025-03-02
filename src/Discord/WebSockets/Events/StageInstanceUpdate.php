<?php

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-present David Cole <david.cole1340@gmail.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\WebSockets\Events;

use Discord\Parts\Channel\StageInstance;
use Discord\WebSockets\Event;
use Discord\Helpers\Deferred;

class StageInstanceUpdate extends Event
{
    /**
     * @inheritdoc
     */
    public function handle(Deferred &$deferred, $data): void
    {
        $stage_instance = $this->factory->create(StageInstance::class, $data, true);

        if ($guild = $this->discord->guilds->get('id', $stage_instance->guild_id)) {
            $old = $guild->stage_instances->get('id', $stage_instance->id);
            $guild->stage_instances->push($stage_instance);
        }

        $deferred->resolve([$stage_instance, $old]);
    }
}

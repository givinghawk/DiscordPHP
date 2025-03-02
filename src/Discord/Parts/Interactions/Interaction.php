<?php

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-present David Cole <david.cole1340@gmail.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Parts\Interactions;

use Discord\Builders\MessageBuilder;
use Discord\Helpers\Multipart;
use Discord\Http\Endpoint;
use Discord\InteractionResponseType;
use Discord\InteractionType;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\Command\Choice;
use Discord\Parts\Interactions\Request\InteractionData;
use Discord\Parts\Part;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use React\Promise\ExtendedPromiseInterface;

use function React\Promise\reject;

/**
 * Represents an interaction from Discord.
 *
 * @see https://discord.com/developers/docs/interactions/receiving-and-responding#interactions
 *
 * @property string               $id             ID of the interaction.
 * @property string               $application_id ID of the application the interaction is for.
 * @property int                  $type           Type of interaction.
 * @property InteractionData|null $data           Data associated with the interaction.
 * @property string|null          $guild_id       ID of the guild the interaction was sent from.
 * @property Guild|null           $guild          Guild the interaction was sent from.
 * @property string|null          $channel_id     ID of the channel the interaction was sent from.
 * @property Channel|null         $channel        Channel the interaction was sent from.
 * @property Member|null          $member         Member who invoked the interaction.
 * @property User|null            $user           User who invoked the interaction.
 * @property string               $token          Continuation token for responding to the interaction.
 * @property int                  $version        Version of interaction.
 * @property Message|null         $message        Message that triggered the interactions, when triggered from message components.
 * @property string|null          $locale         The selected language of the invoking user.
 * @property string|null          $guild_locale   The guild's preferred locale, if invoked in a guild.
 */
class Interaction extends Part
{
    /**
     * @inheritdoc
     */
    protected $fillable = [
        'id',
        'application_id',
        'type',
        'data',
        'guild_id',
        'channel_id',
        'member',
        'user',
        'token',
        'version',
        'message',
        'locale',
        'guild_locale',
    ];

    /**
     * @inheritdoc
     */
    protected $visible = ['guild', 'channel'];

    /**
     * Whether we have responded to the interaction yet.
     *
     * @var bool
     */
    protected $responded = false;

    /**
     * Returns the data associated with the interaction.
     *
     * @return InteractionData|null
     */
    protected function getDataAttribute(): ?InteractionData
    {
        if (! isset($this->attributes['data'])) {
            return null;
        }

        $adata = $this->attributes['data'];
        if (isset($this->attributes['guild_id'])) {
            $adata->guild_id = $this->guild_id;
        }

        return $this->factory->create(InteractionData::class, $adata, true);
    }

    /**
     * Returns the guild the interaction was invoked from. Null when invoked via DM.
     *
     * @return Guild|null
     */
    protected function getGuildAttribute(): ?Guild
    {
        return $this->discord->guilds->get('id', $this->guild_id);
    }

    /**
     * Returns the channel the interaction was invoked from.
     *
     * @return Channel|null
     */
    protected function getChannelAttribute(): ?Channel
    {
        if ($this->guild && $channel = $this->guild->channels->get('id', $this->channel_id)) {
            return $channel;
        }

        return $this->discord->getChannel($this->channel_id);
    }

    /**
     * Returns the member who invoked the interaction. Null when invoked via DM.
     *
     * @return Member|null
     */
    protected function getMemberAttribute(): ?Member
    {
        if (isset($this->attributes['member'])) {
            if ($this->guild && $member = $this->guild->members->get('id', $this->attributes['member']->user->id)) {
                return $member;
            }

            return $this->factory->create(Member::class, (array) $this->attributes['member'] + ['guild_id' => $this->guild_id], true);
        }

        return null;
    }

    /**
     * Returns the user who invoked the interaction.
     *
     * @return User|null
     */
    protected function getUserAttribute(): ?User
    {
        if ($this->member) {
            return $this->member->user;
        }

        if (! isset($this->attributes['user'])) {
            return null;
        }

        return $this->factory->create(User::class, $this->attributes['user'], true);
    }

    /**
     * Returns the message that triggered the interaction, when triggered via message components.
     *
     * @return Message|null
     */
    protected function getMessageAttribute(): ?Message
    {
        if (isset($this->attributes['message'])) {
            return $this->factory->create(Message::class, $this->attributes['message'], true);
        }

        return null;
    }

    /**
     * Acknowledges an interaction without returning a response.
     * Only valid for message component interactions.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#responding-to-an-interaction
     *
     * @throws \LogicException
     *
     * @return ExtendedPromiseInterface
     */
    public function acknowledge(): ExtendedPromiseInterface
    {
        if ($this->type == InteractionType::APPLICATION_COMMAND) {
            return $this->acknowledgeWithResponse();
        }

        if ($this->type != InteractionType::MESSAGE_COMPONENT) {
            return reject(new \LogicException('You can only acknowledge message component interactions.'));
        }

        return $this->respond([
            'type' => InteractionResponseType::DEFERRED_UPDATE_MESSAGE,
        ]);
    }

    /**
     * Acknowledges an interaction, creating a placeholder response message which can be edited later
     * through the `updateOriginalResponse` function.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#responding-to-an-interaction
     *
     * @param bool $ephemeral Whether the acknowledge should be ephemeral.
     *
     * @throws \LogicException
     *
     * @return ExtendedPromiseInterface
     */
    public function acknowledgeWithResponse(bool $ephemeral = false): ExtendedPromiseInterface
    {
        if (! in_array($this->type, [InteractionType::APPLICATION_COMMAND, InteractionType::MESSAGE_COMPONENT])) {
            return reject(new \LogicException('You can only acknowledge application command or message component interactions.'));
        }

        return $this->respond([
            'type' => InteractionResponseType::DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE,
            'data' => $ephemeral ? ['flags' => 64] : [],
        ]);
    }

    /**
     * Updates the message that the interaction was triggered from.
     * Only valid for message component interactions.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#responding-to-an-interaction
     *
     * @param MessageBuilder $builder The new message content.
     *
     * @throws \LogicException
     *
     * @return ExtendedPromiseInterface
     */
    public function updateMessage(MessageBuilder $builder): ExtendedPromiseInterface
    {
        if ($this->type != InteractionType::MESSAGE_COMPONENT) {
            return reject(new \LogicException('You can only update messages that occur due to a message component interaction.'));
        }

        return $this->respond([
            'type' => InteractionResponseType::UPDATE_MESSAGE,
            'data' => $builder,
        ], $builder->requiresMultipart() ? $builder->toMultipart(false) : null);
    }

    /**
     * Retrieves the original interaction response.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#get-original-interaction-response
     *
     * @throws \RuntimeException
     *
     * @return ExtendedPromiseInterface<Message>
     */
    public function getOriginalResponse(): ExtendedPromiseInterface
    {
        if (! $this->responded) {
            return reject(new \RuntimeException('Interaction has not been responded to.'));
        }

        return $this->http->get(Endpoint::bind(Endpoint::ORIGINAL_INTERACTION_RESPONSE, $this->application_id, $this->token))
            ->then(function ($response) {
                return $this->factory->create(Message::class, $response, true);
            });
    }

    /**
     * Updates the original interaction response.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#edit-original-interaction-response
     *
     * @param MessageBuilder $builder New message contents.
     *
     * @throws \RuntimeException
     *
     * @return ExtendedPromiseInterface<Message>
     */
    public function updateOriginalResponse(MessageBuilder $builder): ExtendedPromiseInterface
    {
        if (! $this->responded) {
            return reject(new \RuntimeException('Interaction has not been responded to.'));
        }

        return (function () use ($builder): ExtendedPromiseInterface {
            if ($builder->requiresMultipart()) {
                $multipart = $builder->toMultipart();

                return $this->http->patch(Endpoint::bind(Endpoint::ORIGINAL_INTERACTION_RESPONSE, $this->application_id, $this->token), (string) $multipart, $multipart->getHeaders());
            }

            return $this->http->patch(Endpoint::bind(Endpoint::ORIGINAL_INTERACTION_RESPONSE, $this->application_id, $this->token), $builder);
        })()->then(function ($response) {
            return $this->factory->create(Message::class, $response, true);
        });
    }

    /**
     * Deletes the original interaction response.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#delete-original-interaction-response
     *
     * @throws \RuntimeException
     *
     * @return ExtendedPromiseInterface
     */
    public function deleteOriginalResponse(): ExtendedPromiseInterface
    {
        if (! $this->responded) {
            return reject(new \RuntimeException('Interaction has not been responded to.'));
        }

        return $this->http->delete(Endpoint::bind(Endpoint::ORIGINAL_INTERACTION_RESPONSE, $this->application_id, $this->token));
    }

    /**
     * Sends a follow-up message to the interaction.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#create-followup-message
     *
     * @param MessageBuilder $builder   Message to send.
     * @param bool           $ephemeral Whether the created follow-up should be ephemeral.
     *
     * @throws \RuntimeException
     *
     * @return ExtendedPromiseInterface<Message>
     */
    public function sendFollowUpMessage(MessageBuilder $builder, bool $ephemeral = false): ExtendedPromiseInterface
    {
        if (! $this->responded) {
            return reject(new \RuntimeException('Cannot create a follow-up message as the interaction has not been responded to.'));
        }

        if ($ephemeral) {
            $builder->_setFlags(64);
        }

        return (function () use ($builder): ExtendedPromiseInterface {
            if ($builder->requiresMultipart()) {
                $multipart = $builder->toMultipart();

                return $this->http->post(Endpoint::bind(Endpoint::CREATE_INTERACTION_FOLLOW_UP, $this->application_id, $this->token), (string) $multipart, $multipart->getHeaders());
            }

            return $this->http->post(Endpoint::bind(Endpoint::CREATE_INTERACTION_FOLLOW_UP, $this->application_id, $this->token), $builder);
        })()->then(function ($response) {
            return $this->factory->create(Message::class, $response, true);
        });
    }

    /**
     * Responds to the interaction with a message.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#create-interaction-response
     *
     * @param MessageBuilder $builder   Message to respond with.
     * @param bool           $ephemeral Whether the created message should be ephemeral.
     *
     * @throws \LogicException
     *
     * @return ExtendedPromiseInterface
     */
    public function respondWithMessage(MessageBuilder $builder, bool $ephemeral = false): ExtendedPromiseInterface
    {
        if (! in_array($this->type, [InteractionType::APPLICATION_COMMAND, InteractionType::MESSAGE_COMPONENT])) {
            return reject(new \LogicException('You can only acknowledge application command or message component interactions.'));
        }

        if ($ephemeral) {
            $builder->_setFlags(64);
        }

        return $this->respond([
            'type' => InteractionResponseType::CHANNEL_MESSAGE_WITH_SOURCE,
            'data' => $builder,
        ], $builder->requiresMultipart() ? $builder->toMultipart(false) : null);
    }

    /**
     * Responds to the interaction with a payload.
     *
     * This is a seperate function so that it can be overloaded when responding via
     * webhook.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#create-interaction-response
     *
     * @param array          $payload   Response payload.
     * @param Multipart|null $multipart Optional multipart payload.
     *
     * @throws \RuntimeException
     *
     * @return ExtendedPromiseInterface
     */
    protected function respond(array $payload, ?Multipart $multipart = null): ExtendedPromiseInterface
    {
        if ($this->responded) {
            return reject(new \RuntimeException('Interaction has already been responded to.'));
        }

        $this->responded = true;

        if ($multipart) {
            $multipart->add([
                'name' => 'payload_json',
                'content' => json_encode($payload),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            return $this->http->post(Endpoint::bind(Endpoint::INTERACTION_RESPONSE, $this->id, $this->token), (string) $multipart, $multipart->getHeaders());
        }

        return $this->http->post(Endpoint::bind(Endpoint::INTERACTION_RESPONSE, $this->id, $this->token), $payload);
    }

    /**
     * Updates a non ephemeral follow up message.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#edit-followup-message
     *
     * @param string         $message_id Message to update.
     * @param MessageBuilder $builder    New message contents.
     *
     * @throws \RuntimeException
     *
     * @return ExtendedPromiseInterface<Message>
     */
    public function updateFollowUpMessage(string $message_id, MessageBuilder $builder)
    {
        if (! $this->responded) {
            return reject(new \RuntimeException('Cannot create a follow-up message as the interaction has not been responded to.'));
        }

        return (function () use ($message_id, $builder): ExtendedPromiseInterface {
            if ($builder->requiresMultipart()) {
                $multipart = $builder->toMultipart();

                return $this->http->patch(Endpoint::bind(Endpoint::INTERACTION_FOLLOW_UP, $this->application_id, $this->token, $message_id), (string) $multipart, $multipart->getHeaders());
            }

            return $this->http->patch(Endpoint::bind(Endpoint::INTERACTION_FOLLOW_UP, $this->application_id, $this->token, $message_id), $builder);
        })()->then(function ($response) {
            return $this->factory->create(Message::class, $response, true);
        });
    }

    /**
     * Retrieves a non ephemeral follow up message.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#get-followup-message
     *
     * @param string $message_id Message to get.
     *
     * @throws \RuntimeException
     *
     * @return ExtendedPromiseInterface<Message>
     */
    public function getFollowUpMessage(string $message_id): ExtendedPromiseInterface
    {
        if (! $this->responded) {
            return reject(new \RuntimeException('Interaction has not been responded to.'));
        }

        return $this->http->get(Endpoint::bind(Endpoint::INTERACTION_FOLLOW_UP, $this->application_id, $this->token, $message_id))
            ->then(function ($response) {
                return $this->factory->create(Message::class, $response, true);
            });
    }

    /**
     * Deletes a non ephemeral follow up message.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#delete-followup-message
     *
     * @param string $message_id Message to delete.
     *
     * @throws \RuntimeException
     *
     * @return ExtendedPromiseInterface
     */
    public function deleteFollowUpMessage(string $message_id): ExtendedPromiseInterface
    {
        if (! $this->responded) {
            return reject(new \RuntimeException('Interaction has not been responded to.'));
        }

        return $this->http->delete(Endpoint::bind(Endpoint::INTERACTION_FOLLOW_UP, $this->application_id, $this->token, $message_id));
    }

    /**
     * Responds to the interaction with auto complete suggestions.
     *
     * @see https://discord.com/developers/docs/interactions/receiving-and-responding#responding-to-an-interaction
     *
     * @param array|Choice[] $choice Autocomplete choices (max of 25 choices)
     *
     * @throws \LogicException
     *
     * @return ExtendedPromiseInterface
     */
    public function autoCompleteResult(array $choices): ExtendedPromiseInterface
    {
        if ($this->type != InteractionType::APPLICATION_COMMAND_AUTOCOMPLETE) {
            return reject(new \LogicException('You can only respond command option results with auto complete interactions.'));
        }

        return $this->respond([
            'type' => InteractionResponseType::APPLICATION_COMMAND_AUTOCOMPLETE_RESULT,
            'data' => ['choices' => $choices],
        ]);
    }
}

<?php

class ClientController extends Controller
{
    use PhoneWithMaskTrat;

    /**
     * @var ClientService
     */
    private $clientService;

    /**
     * @var ServiceService
     */
    private $serviceService;

    /**
     * @var ClientOrderService
     */
    private $clientOrderService;

    /**
     * ClientController constructor.
     * @param ClientService $clientService
     * @param ServiceService $serviceService
     * @param ClientOrderService $clientOrderService
     */
    public function __construct(
        ClientService $clientService,
        ServiceService $serviceService,
        ClientOrderService $clientOrderService
    )
    {
        $this->clientService = $clientService;
        $this->serviceService = $serviceService;
        $this->clientOrderService = $clientOrderService;
    }

    /**
     * Display a listing of the resource.
     *
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreRequest $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function store(StoreRequest $request)
    {
        $this->authorize('create', Client::class);
        $this->clientService
            ->createClient(
                $request->phone,
                $request->email,
                $request->password,
                $request->first_name,
                $request->last_name,
                $request->patronymic,
                $request->referral_code
            );

        if ($request->exists('is_enable_send_password') && $request->is_enable_send_code) {
            try {
                app(SmsSender::class)->send($request->phone, "Пароль: {$request->password}");
            } catch (\Exception $e) {
                $errors = [
                    'email' => [
                        'Ошибка отправки сообщения с паролем клиенту.'
                    ]
                ];

                return $this->returnValidationError($errors);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Пользователь успешно создан.',
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param Client $client
     * @return ClientResource
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function show(Client $client)
    {
        $this->authorize('view', $client);

        return new ClientResource($client);
    }

    /**
     * @param Client $client
     * @return \Symfony\Component\HttpFoundation\StreamedResponse | \Illuminate\Http\JsonResponse
     */
    public function getAvatarByClientId(Client $client)
    {
        $media = $client->getAvatar();

        if (!$media) {
            return response()->json('', 200);
        }

        try {
            $content = stream_get_contents($media->stream());
        } catch (\Exception $exception) {
            return response()->json(['error' => $exception->getMessage()], 200);
        }

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $media->file_name);
    }

    /**
     * @param Request $request
     * @return ClientCollection
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function filter(Request $request)
    {
        $this->authorize('viewAny', Client::class);
        $clients = Client::query();

        if ($request->exists('id')) {
            $clients->where('id', $request->id);
        }

        if ($request->exists('phone')) {
            $clients->byPhone($request->phone);
        }

        if ($request->exists('email')) {
            $clients->byEmail($request->email);
        }

        if ($request->exists('referral_code')) {
            $clients->byReferralCode($request->referral_code);
        }

        if ($request->exists('first_name')) {
            $clients->where('first_name', 'ilike', '%' . $request->first_name . '%');
        }

        if ($request->exists('last_name')) {
            $clients->where('last_name', 'ilike', '%' . $request->last_name . '%');
        }

        if ($request->exists('patronymic')) {
            $clients->where('patronymic', 'ilike', '%' . $request->patronymic . '%');
        }

        if ($request->exists('created_at_from') && $request->exists('created_at_to')) {
            $clients->whereDate('created_at', '>=', Carbon::parse($request->created_at_from / 1000))
                ->whereDate('created_at', '<=', Carbon::parse($request->created_at_to / 1000));
        }

        if ($request->exists('inactive')) {
            $clients->where('active', false);
        }

        if ($request->exists('agent_status')) {
            $clients->where('agent_status', $request->agent_status);
        }

        if ($request->exists('hasLk')) {
            $clients->where('has_lk', $request->hasLk);
        }

        return new ClientCollection(
            $clients->with(['orders', 'points'])
                ->orderByDesc('id')
                ->paginate(50));
    }

    /**
     * @param Request $request
     * @param Client $client
     * @return ClientCollection
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function referralsFilterByClient(Request $request, Client $client)
    {
        $this->authorize('viewAny', Client::class);
        $clients = $client->referrals();

        if ($request->exists('id')) {
            $clients->where('id', $request->id);
        }

        if ($request->exists('phone')) {
            $clients->byPhone($request->phone);
        }

        if ($request->exists('email')) {
            $clients->byEmail($request->email);
        }

        if ($request->exists('referral_code')) {
            $clients->byReferralCode($request->referral_code);
        }

        if ($request->exists('first_name')) {
            $clients->where('first_name', $request->first_name);
        }

        if ($request->exists('last_name')) {
            $clients->where('last_name', $request->last_name);
        }

        if ($request->exists('patronymic')) {
            $clients->where('patronymic', $request->patronymic);
        }

        if ($request->exists('created_at_from') && $request->exists('created_at_to')) {
            $clients->whereDate('created_at', '>=', Carbon::parse($request->created_at_from / 1000))
                ->whereDate('created_at', '<=', Carbon::parse($request->created_at_to / 1000));
        }

        if ($request->exists('inactive')) {
            $clients->where('active', false);
        }

        return new ClientCollection(
            $clients->with(['orders', 'points'])
                ->orderByDesc('id')
                ->paginate(20));
    }

    /**
     * @param SearchRequest $request
     * @return \Illuminate\Http\JsonResponse | ClientCollection
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function search(SearchRequest $request)
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::query()
            ->where(function ($query) use ($request) {
                $query->where('id', 'like', '%' . $request->client_data . '%')
                    ->orWhere('phone', 'like', '%' . $request->client_data . '%')
                    ->orWhere('email', 'like', '%' . $request->client_data . '%')
                    ->orWhere('first_name', 'ilike', $request->client_data . '%')
                    ->orWhere('last_name', 'ilike', $request->client_data . '%')
                    ->orWhere('patronymic', 'ilike', $request->client_data . '%');
            });

        if ($request->mode && $request->mode === 'without_orders') {
            $clients = $clients->whereDoesntHave($request->mode === 'without_orders' ? 'orders' : '');
        }

        $clients = $clients->limit(31)
            ->get();

        if ($clients->count() > 30) {
            return response()->json([
                'status' => 'error',
                'message' => 'Кол-во вариантов слишком большое, уточните запрос'
            ]);
        }

        return new ClientCollection($clients);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateRequest $request
     * @param Client $client
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function update(UpdateRequest $request, Client $client)
    {
        $this->authorize('update', $client);

        $clientData = $request->only(['email', 'phone', 'first_name', 'last_name', 'patronymic', 'agent_status', 'bank_requisites', 'active']);
        if ($clientData['phone'] === $this->phoneWithMask($client->phone)) {
            unset($clientData['phone']);
        }
        $client->update($clientData);

        return response()->json([
            'status' => 'success',
            'message' => 'Пользовательские данные успешно обновлены.',
        ]);
    }

    /**
     * @param Request $request
     * @param Client $client
     * @return \Illuminate\Http\JsonResponse
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\DiskDoesNotExist
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\FileIsTooBig
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function uploadAvatar(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $client->setAvatar($request->avatar);

        return response()->json([
            'status' => 'success',
            'message' => 'Аватар успешно обновлен.',
        ]);
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param Client $client
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function destroy(Client $client)
    {
        $this->authorize('delete', $client);

        $client->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Успешное удаление пользователя.',
        ]);
    }

    /**
     * @param Client $client
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function disableClient(Client $client)
    {
        $this->authorize('update', $client);

        $client->update([
            'active' => false
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Пользователь успешно заблокирован.',
        ]);
    }

    /**
     * @param Client $client
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function activateClient(Client $client)
    {
        $this->authorize('update', $client);

        $client->update([
            'active' => true
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Пользователь успешно разблокирован.',
        ]);
    }
}

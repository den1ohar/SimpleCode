<?php

class PointController extends Controller
{
    /**
     * @var ClientPointsService
     */
    private $clientPointsService;

    /**
     * @var ClientService
     */
    private $clientService;

    /**
     * PointController constructor.
     * @param ClientPointsService $clientPointsService
     * @param ClientService $clientService
     */
    public function __construct(ClientPointsService $clientPointsService, ClientService $clientService)
    {
        $this->clientPointsService = $clientPointsService;
        $this->clientService = $clientService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index()
    {

    }

    /**
     * @param Request $request
     * @return PointCollection
     * @throws AuthorizationException
     */
    public function filter(Request $request)
    {
        $this->authorize('viewAny', Points::class);

        $points = Points::query();

        if ($request->exists('mode') && $request->mode === 'client_requests') {
            $points->whereIn('status', [Points::STATUS_REQUEST_WITHDRAWAL, Points::STATUS_REQUEST_PARTNER_CERT]);
        }

        if ($request->exists('id')) {
            $points->where('id', $request->id);
        }

        if ($request->exists('client_id')) {
            $points->where('client_id', $request->client_id);
        }

        if ($request->exists('value')) {
            $points->where('value', 'like', '%' . $request->value . '%');
        }

        if ($request->exists('status')) {
            $points->where('status', $request->status);
        }

        if ($request->exists('comment')) {
            $points->where('comment', $request->comment);
        }

        if ($request->exists('client_type')) {
            $clientType = $request->input('client_type');

            $points->whereHas('client', function ($query) use ($clientType) {
                if ($clientType === 'is_agent') {
                    $query->where('agent_status', Client::AGENT_STATUS_YES);
                } else {
                    $query->where('agent_status', Client::AGENT_STATUS_NO)
                        ->orWhere('agent_status', Client::AGENT_STATUS_REQUEST);
                }
            });
        }

        if ($request->exists('status_type')) {
            $points->where('status_type', $request->input('status_type'));
        }

        return new PointCollection($points->with('client')->paginate(50));
    }

    /**
     * @return JsonResponse
     */
    public function getStatuses()
    {
        return response()->json([
            'data' => $this->clientPointsService->getStatuses()
        ]);
    }

    /**
     * @param StoreRequest $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function store(StoreRequest $request)
    {
        $this->authorize('create', Points::class);
        $client = Client::find($request->input('client_id'));

        if ($request->input('status') === Points::STATUS_ADD_BY_ADMIN
            || $request->input('status') === Points::STATUS_ADD_BY_ADMIN_PARTNER_CERT) {
            $this->clientPointsService->addByAdmin(
                $client,
                $request->input('value'),
                $request->input('comment'),
                $request->input('partner_id'),
                $request->input('partner_cert_code')
            );
        } elseif ($request->input('status') === Points::STATUS_SAVE_BY_ADMIN_NOT_APPROVE_PARTNER_CERT) {
            $this->clientPointsService->saveByAdmin(
                $client,
                $request->input('value'),
                $request->input('comment'),
                $request->input('partner_id'),
                $request->input('partner_cert_code')
            );
        } else if ($request->input('status') === Points::STATUS_SUB_BY_ADMIN) {
            $this->clientPointsService->subByAdmin(
                $client,
                $request->input('value'),
                $request->input('comment')
            );
        }

        return $this->makeSuccessResponse('Запись успешно создана.');
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function get(Request $request)
    {
        $this->authorize('viewAny', Points::class);

        if (!$request->id) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'client_id' => [
                        'value' => null,
                        'active' => true
                    ],
                    'value' => [
                        'value' => null,
                        'active' => true
                    ],
                    'status' => [
                        'value' => null,
                        'active' => true,
                        'variants' => $this->clientPointsService->getPossibleStatuses(),
                    ],
                    'comment' => [
                        'value' => null,
                        'active' => true
                    ],
                    'partner_cert_code' => [
                        'value' => null,
                        'active' => true,
                    ],
                    'partner_id' => [
                        'value' => null,
                        'active' => true
                    ]
                ]
            ]);
        }

        /** @var Points $points */
        $points = Points::findOrFail($request->id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'client_id' => [
                    'value' => $points->client_id,
                    'active' => false
                ],
                'value' => [
                    'value' => $points->value,
                    'active' => false
                ],
                'status' => [
                    'value' => $points->status,
                    'active' => false,
                    'variants' => $this->clientPointsService->getPossibleStatuses($points)
                ],
                'comment' => [
                    'value' => $points->comment,
                    'active' => true
                ],
                'partner_cert_code' => [
                    'value' => $points->partner_cert_code,
                    'active' => $points->statusIsRequestPartnerCert() || $points->statusIsSaveByAdminNotApproveCert()
                ],
                'partner_id' => [
                    'value' => $points->partner_id,
                    'active' => false
                ],
                'certificate' => [
                    'value' => $points->value,
                    'active' => false
                ]
            ]
        ]);
    }

    /**
     * @param Request $request
     * @param Points $point
     * @return PointResource
     */
    public function show(Request $request, Points $point)
    {
        return new PointResource($point);
    }

    /**
     * @param UpdateRequest $request
     * @param Points $point
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function update(UpdateRequest $request, Points $point)
    {
        $this->authorize('update', $point);

        if ($request->exists('comment')) {
            $point->comment = $request->comment;
        }
        if ($request->exists('partner_cert_code')) {
            $point->partner_cert_code = $request->partner_cert_code;
        }

        $point->save();

        return $this->makeSuccessResponse('Запись успешно обновлена.');
    }

    /**
     * @param Points $point
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function destroy(Points $point)
    {
        $this->authorize('update', $point);
        $result = $this->clientPointsService->cancelWithdrawal($point);

        if (!$result) {
            return $this->makeErrorResponse($this->clientPointsService->getErrors());
        }

        return $this->makeSuccessResponse('Запись успешно удалена.');
    }


    /**
     * @param Request $request
     * @param Points $point
     * @return PointResource|JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function accept(Request $request, Points $point)
    {
        $this->authorize('update', $point);

        if ($point->statusIsRequestWithdrawal()) {
            if (!$this->clientPointsService->acceptWithdrawal($point)) {
                return $this->makeErrorResponse($this->clientPointsService->getErrors());
            }
        } elseif ($point->statusIsRequestPartnerCert() || $point->statusIsSaveByAdminNotApproveCert()) {
            if (!$this->clientPointsService->acceptPartnerCert($point)) {
                return $this->makeErrorResponse($this->clientPointsService->getErrors());
            }
        }

        return new PointResource($point);
    }


    /**
     * @param Points $point
     * @return PointResource|JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function cancel(Points $point)
    {
        $this->authorize('update', $point);

        if ($point->statusIsRequestWithdrawal()) {
            if (!$this->clientPointsService->cancelWithdrawal($point)) {
                return $this->makeErrorResponse($this->clientPointsService->getErrors());
            }
        } elseif ($point->statusIsRequestPartnerCert()) {
            if (!$this->clientPointsService->cancelPartnerCert($point)) {
                return $this->makeErrorResponse($this->clientPointsService->getErrors());
            }
        }

        return new PointResource($point);
    }

    /**
     * @param null $message
     * @return JsonResponse
     */
    private function makeSuccessResponse($message = null)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message ? $message : 'Запрос выполнен успешно.',
        ]);
    }

    /**
     * @param $errors
     * @return JsonResponse
     */
    private function makeErrorResponse($errors)
    {
        return response()->json([
            'status' => 'error',
            'errors' => $errors,
        ], 422);
    }
}
